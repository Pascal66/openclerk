<?php

/**
 * Batch script: find a job to execute, and then execute it.
 * We don't use meta-jobs to insert in new jobs, because we implement
 * job priority and we don't want to have to also use 'execute_after'.
 *
 * Arguments (in command line, use "-" for no argument):
 *   $key/1 required the automated key
 *   $job_type/2 optional restrict job execution to only this type of job, comma-separated list
 *   $job_id/3 optional run the given job ID
 *   $force/4 optional if true, force the job to run even if it has failed before
 */

define('BATCH_JOB_START', microtime(true));
define('USE_MASTER_DB', true);    // always use the master database for selects!

if (!defined('ADMIN_RUN_JOB')) {
  require(__DIR__ . "/../inc/global.php");
}
require(__DIR__ . "/_batch.php");
require(__DIR__ . "/_batch_insert.php");

require_batch_key();
batch_header("Batch run", "batch_run");

$job_types = array(); // default to running all jobs
if (isset($argv[2]) && $argv[2] && $argv[2] != "-") {
  $job_types = explode(",", $argv[2]);
} else if (require_get("job_type", false)) {
  $job_types = explode(",", require_get("job_type"));
}

if (isset($argv[3]) && $argv[3] && $argv[3] != "-") {
    // run a particular job, even if it's already been executed
    $q = db_master()->prepare("SELECT * FROM jobs WHERE id=?");
    $q->execute(array((int) $argv[3]));
    $job = $q->fetch();
} else if (require_get("job_id", false)) {
  // run a particular job, even if it's already been executed
  $q = db_master()->prepare("SELECT * FROM jobs WHERE id=?");
  $q->execute(array((int) require_get("job_id")));
  $job = $q->fetch();
} else {
  // select a particular type of job (allows more fine-grained control over regularly important jobs, such as ticker)
  $job_type_where = "";
  if ($job_types) {
    foreach ($job_types as $jt) {
      $job_type_where .= ($job_type_where ? ", " : "") . "'" . $jt . "'";
    }
    $job_type_where = " AND job_type IN (" . $job_type_where . ") ";
  }

  // find all jobs that have crashed (that have taken longer than five minutes) and mark them as errored
  $q = db_master()->prepare("UPDATE jobs SET is_executing=0,execution_count=execution_count+1,is_error=1,is_timeout=1 WHERE is_executing=1 AND
    ((is_test_job=0 AND execution_started < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) OR
    (is_test_job=1 AND execution_started < DATE_SUB(NOW(), INTERVAL 1 MINUTE)))");
  $q->execute();

  // don't execute another job if we're running too many jobs already
  $q = db_master()->prepare("SELECT COUNT(*) AS c FROM jobs WHERE is_executing=1");
  $q->execute();
  $job_count = $q->fetch();
  if ($job_count['c'] >= get_site_config('maximum_jobs_running')) {
    crypto_log("Not running any more jobs: too many jobs are running already (" . get_site_config('maximum_jobs_running') . ")");
    return;
  }

  // select the most important job to execute next
  $q = db_master()->prepare("SELECT * FROM jobs WHERE is_executed=0 AND is_executing=0 $job_type_where ORDER BY priority ASC, id ASC LIMIT 20");
  $q->execute();

  // iterate until we find a job that we can actually run right now
  while ($job = $q->fetch()) {
    $throttle = get_site_config('throttle_' . $job['job_type'], false);
    if ($throttle) {
      // find the last executed job
      $q1 = db_master()->prepare("SELECT * FROM jobs WHERE is_executed=1 AND job_type=? AND executed_at > date_sub(now(), interval ? second) LIMIT 1");
      $q1->execute(array($job['job_type'], $throttle));
      if ($early = $q1->fetch()) {
        crypto_log("Cannot run job " . $job['id'] . " (" . $job['job_type'] . ": another job " . $early['job_type'] . " was run less than $throttle seconds ago (" . $early['id'] . ")");
      } else {
        // we've found a job we can execute
        break;
      }
    } else {
      // we've found a job we can execute
      break;
    }
  }
}

$force = require_get('force', false) || (isset($argv[4]) && $argv[4] != "-" && $argv[4]);

if (!$job) {
  // nothing to do!
  crypto_log("No job to execute.");
  return;
} else if (!get_site_config('jobs_enabled')) {
  // we've disabled jobs for now
  crypto_log("Job execution disabled ('jobs_enabled').");
  return;
}

crypto_log("Current time: " . date('r'));

// otherwise, we'll want to actually execute something, based on the job type
// TODO remove the navigation links once we have an actual job admin interface
crypto_log("Executing job " . htmlspecialchars(print_r($job, true)) . " (<a href=\"" . htmlspecialchars(url_for('batch/batch_run',
  array('key' => require_get("key", false), 'job_id' => $job['id'], 'force' => 1))) . "\">re-run job</a>) (<a href=\"" . htmlspecialchars(url_for('batch/batch_run',
  array('key' => require_get("key", false)))) . "\">next job</a>)");

$runtime_exception = null;
try {
  // have we executed this job too many times already?
  // (we check this here so our exception handling code below can capture it)
  if ($job['is_test_job'] && $job['is_error'] && !$force) {
    crypto_log("Job is a test job and threw an error straight away; marking as failed");
    if ($job['is_timeout']) {
      throw new ExternalAPIException("Local timeout");
    } else {
      throw new ExternalAPIException("Job failed for an unknown reason");
    }
  } else if ($job['execution_count'] >= get_site_config("max_job_executions") && !$force) {
    // TODO this job should be debugged in dev and fixed so that an execption can be thrown instead
    crypto_log("Job has been executed too many times (" . number_format($job['execution_count']) . "): marking as failed");
    throw new ExternalAPIException("An uncaught error occured multiple times");
  } else {
    // update old jobs that they are no longer recent
    // assumes all jobs can be grouped by (job_type,user_id,arg_id)
    $q = db_master()->prepare("UPDATE jobs SET is_recent=0 WHERE is_recent=1 AND job_type=? AND user_id=? AND arg_id=?");
    $q->execute(array($job['job_type'], $job['user_id'], $job['arg_id']));

    // update the job execution count
    $q = db_master()->prepare("UPDATE jobs SET is_executing=1,execution_count=execution_count+1,is_recent=1,execution_started=NOW() WHERE id=?");
    $q->execute(array($job['id']));
  }

  // sanity check
  if (!function_exists('curl_init')) {
    throw new Exception("curl_init() function does not exist");
  }

  switch ($job['job_type']) {
    // ticker jobs
    case "ticker":
      require(__DIR__ . "/../jobs/ticker.php");
      break;

    case "reported_currencies":
      require(__DIR__ . "/../jobs/reported_currencies.php");
      break;

    // account jobs
    case "generic":
      require(__DIR__ . "/../jobs/generic.php");
      break;

    case "bit2c":
      require(__DIR__ . "/../jobs/bit2c.php");
      break;

    case "btce":
      require(__DIR__ . "/../jobs/btce.php");
      break;

    case "vircurex":
      require(__DIR__ . "/../jobs/vircurex.php");
      break;

    case "poolx":
      require(__DIR__ . "/../jobs/poolx.php");
      break;

    case "wemineltc":
      require(__DIR__ . "/../jobs/wemineltc.php");
      break;

    case "wemineftc":
      require(__DIR__ . "/../jobs/wemineftc.php");
      break;

    case "givemecoins":
      require(__DIR__ . "/../jobs/givemecoins.php");
      break;

    case "slush":
      require(__DIR__ . "/../jobs/slush.php");
      break;

    case "cryptostocks":
      require(__DIR__ . "/../jobs/cryptostocks.php");
      break;

    case "securities_cryptostocks":
      require(__DIR__ . "/../jobs/securities_cryptostocks.php");
      break;

    case "btcguild":
      require(__DIR__ . "/../jobs/btcguild.php");
      break;

    case "havelock":
      require(__DIR__ . "/../jobs/havelock.php");
      break;

    case "securities_havelock":
      require(__DIR__ . "/../jobs/securities_havelock.php");
      break;

    case "bitminter":
      require(__DIR__ . "/../jobs/bitminter.php");
      break;

    case "liteguardian":
      require(__DIR__ . "/../jobs/liteguardian.php");
      break;

    case "khore":
      require(__DIR__ . "/../jobs/khore.php");
      break;

    case "cexio":
      require(__DIR__ . "/../jobs/cexio.php");
      break;

    case "ghashio":
      require(__DIR__ . "/../jobs/ghashio.php");
      break;

    case "crypto-trade":
      require(__DIR__ . "/../jobs/crypto-trade.php");
      break;

    case "securities_crypto-trade":
      require(__DIR__ . "/../jobs/securities_cryptotrade.php");
      break;

    case "bitstamp":
      require(__DIR__ . "/../jobs/bitstamp.php");
      break;

    case "796":
      require(__DIR__ . "/../jobs/796.php");
      break;

    case "securities_796":
      require(__DIR__ . "/../jobs/securities_796.php");
      break;

    case "kattare":
      require(__DIR__ . "/../jobs/kattare.php");
      break;

    case "litepooleu":
      require(__DIR__ . "/../jobs/litepooleu.php");
      break;

    case "coinhuntr":
      require(__DIR__ . "/../jobs/coinhuntr.php");
      break;

    case "eligius":
      require(__DIR__ . "/../jobs/eligius.php");
      break;

    case "litecoinpool":
      require(__DIR__ . "/../jobs/litecoinpool.php");
      break;

    case "elitistjerks":
      require(__DIR__ . "/../jobs/elitistjerks.php");
      break;

    case "hashfaster_ltc":
      require(__DIR__ . "/../jobs/hashfaster_ltc.php");
      break;

    case "hashfaster_ftc":
      require(__DIR__ . "/../jobs/hashfaster_ftc.php");
      break;

    case "hashfaster_doge":
      require(__DIR__ . "/../jobs/hashfaster_doge.php");
      break;

    case "triplemining":
      require(__DIR__ . "/../jobs/triplemining.php");
      break;

    case "ozcoin_ltc":
      require(__DIR__ . "/../jobs/ozcoin_ltc.php");
      break;

    case "ozcoin_btc":
      require(__DIR__ . "/../jobs/ozcoin_btc.php");
      break;

    case "scryptpools":
      require(__DIR__ . "/../jobs/scryptpools.php");
      break;

    case "justcoin":
      require(__DIR__ . "/../jobs/justcoin.php");
      break;

    case "multipool":
      require(__DIR__ . "/../jobs/multipool.php");
      break;

    case "ypool":
      require(__DIR__ . "/../jobs/ypool.php");
      break;

    case "coinbase":
      require(__DIR__ . "/../jobs/coinbase.php");
      break;

    case "litecoininvest":
      require(__DIR__ . "/../jobs/litecoininvest.php");
      break;

    case "miningpoolco":
      require(__DIR__ . "/../jobs/miningpoolco.php");
      break;

    case "vaultofsatoshi":
      require(__DIR__ . "/../jobs/vaultofsatoshi.php");
      break;

    case "50btc":
      require(__DIR__ . "/../jobs/50btc.php");
      break;

    case "ecoining_ppc":
      require(__DIR__ . "/../jobs/ecoining_ppc.php");
      break;

    case "teamdoge":
      require(__DIR__ . "/../jobs/teamdoge.php");
      break;

    case "dedicatedpool_doge":
      require(__DIR__ . "/../jobs/dedicatedpool_doge.php");
      break;

    case "nut2pools_ftc":
      require(__DIR__ . "/../jobs/nut2pools_ftc.php");
      break;

    case "cryptsy":
      require(__DIR__ . "/../jobs/cryptsy.php");
      break;

    case "cryptopools_dgc":
      require(__DIR__ . "/../jobs/cryptopools_dgc.php");
      break;

    case "d2_wdc":
      require(__DIR__ . "/../jobs/d2_wdc.php");
      break;

    case "bit2c":
      require(__DIR__ . "/../jobs/bit2c.php");
      break;

    case "kraken":
      require(__DIR__ . "/../jobs/kraken.php");
      break;

    case "cryptotroll_doge":
      require(__DIR__ . "/../jobs/cryptotroll_doge.php");
      break;

    case "bitmarket_pl":
      require(__DIR__ . "/../jobs/bitmarket_pl.php");
      break;

    case "poloniex":
      require(__DIR__ . "/../jobs/poloniex.php");
      break;

    case "mupool":
      require(__DIR__ . "/../jobs/mupool.php");
      break;

    case "anxpro":
      require(__DIR__ . "/../jobs/anxpro.php");
      break;

    case "bittrex":
      require(__DIR__ . "/../jobs/bittrex.php");
      break;

    case "nicehash":
      require(__DIR__ . "/../jobs/nicehash.php");
      break;

    case "westhash":
      require(__DIR__ . "/../jobs/westhash.php");
      break;

    case "eobot":
      require(__DIR__ . "/../jobs/eobot.php");
      break;

    case "hashtocoins":
      require(__DIR__ . "/../jobs/hashtocoins.php");
      break;

    case "btclevels":
      require(__DIR__ . "/../jobs/btclevels.php");
      break;

    case "bitnz":
      require(__DIR__ . "/../jobs/bitnz.php");
      break;

    // individual securities jobs
    case "individual_cryptostocks":
      require(__DIR__ . "/../jobs/individual_cryptostocks.php");
      break;

    case "individual_havelock":
      require(__DIR__ . "/../jobs/individual_havelock.php");
      break;

    case "individual_crypto-trade":
      require(__DIR__ . "/../jobs/individual_crypto-trade.php");
      break;

    case "individual_796":
      require(__DIR__ . "/../jobs/individual_796.php");
      break;

    case "individual_litecoininvest":
      require(__DIR__ . "/../jobs/individual_litecoininvest.php");
      break;

    // summary jobs
    case "sum":
      require(__DIR__ . "/../jobs/sum.php");
      break;

    case "securities_count":
      require(__DIR__ . "/../jobs/securities_count.php");
      break;

    // notification jobs
    case "notification":
      require(__DIR__ . "/../jobs/notification.php");
      break;

    // system jobs
    case "securities_update":
      require(__DIR__ . "/../jobs/securities_update.php");
      break;

    case "version_check":
      require(__DIR__ . "/../jobs/version_check.php");
      break;

    case "vote_coins":
      require(__DIR__ . "/../jobs/vote_coins.php");
      break;

    // transaction jobs
    case "transaction_creator":
      require(__DIR__ . "/../jobs/transaction_creator.php");
      break;

    case "transactions":
      require(__DIR__ . "/../jobs/transactions.php");
      break;

    // cleanup jobs, admin jobs etc
    case "outstanding":
      require(__DIR__ . "/../jobs/outstanding.php");
      break;

    case "expiring":
      require(__DIR__ . "/../jobs/expiring.php");
      break;

    case "expire":
      require(__DIR__ . "/../jobs/expire.php");
      break;

    case "cleanup":
      require(__DIR__ . "/../jobs/cleanup.php");
      break;

    case "disable_warning":
      require(__DIR__ . "/../jobs/disable_warning.php");
      break;

    case "disable":
      require(__DIR__ . "/../jobs/disable.php");
      break;

    case "delete_user":
      require(__DIR__ . "/../jobs/delete_user.php");
      break;

    default:
      if (substr($job['job_type'], 0, strlen("address_")) === "address_") {
        // address job
        $currency = substr($job['job_type'], strlen("address_"));
        if (!in_array($currency, get_address_currencies())) {
          throw new JobException("Currency $currency is not a valid address currency");
        }
        if (in_array($currency, \DiscoveredComponents\Currencies::getBalanceCurrencies())) {
          require(__DIR__ . "/../jobs/addresses/discovered.php");
        } else {
          // TODO eventually remove this block once we have no currencies that are also in getBalanceCurrencies()
          if (!file_exists(__DIR__ . "/../jobs/addresses/" . safe_include_arg($currency) . ".php")) {
            throw new JobException("Could not find any addresses/$currency.php include");
          }
          require(__DIR__ . "/../jobs/addresses/" . safe_include_arg($currency) . ".php");
        }
        break;
      }

      if (substr($job['job_type'], 0, strlen("blockcount_")) === "blockcount_") {
        // address job
        $currency = substr($job['job_type'], strlen("blockcount_"));
        if (!in_array($currency, \DiscoveredComponents\Currencies::getBlockCurrencies())) {
          throw new JobException("Currency $currency is not a valid block currency");
        }
        require(__DIR__ . "/../jobs/blockcount/discovered.php");
        break;
      }

      if (substr($job['job_type'], 0, strlen("difficulty_")) === "difficulty_") {
        // address job
        $currency = substr($job['job_type'], strlen("difficulty_"));
        if (!in_array($currency, \DiscoveredComponents\Currencies::getDifficultyCurrencies())) {
          throw new JobException("Currency $currency is not a valid difficulty currency");
        }
        require(__DIR__ . "/../jobs/difficulty/discovered.php");
        break;
      }

      if (substr($job['job_type'], 0, strlen("markets_")) === "markets_") {
        // address job
        $exchange = substr($job['job_type'], strlen("markets_"));
        if (!in_array($exchange, \DiscoveredComponents\Exchanges::getKeys())) {
          throw new JobException("Exchange $exchange is not a valid exchange");
        }
        require(__DIR__ . "/../jobs/markets/discovered.php");
        break;
      }

      if (substr($job['job_type'], 0, strlen("ticker_")) === "ticker_") {
        // address job
        $exchange = substr($job['job_type'], strlen("ticker_"));
        if (!in_array($exchange, \DiscoveredComponents\Exchanges::getKeys())) {
          throw new JobException("Exchange $exchange is not a valid exchange");
        }
        require(__DIR__ . "/../jobs/ticker/discovered.php");
        break;
      }

      // issue #12: unsafe accounts
      if (get_site_config('allow_unsafe')) {
        switch ($job['job_type']) {
          // empty for now
        }
      }

      throw new JobException("Unknown job type '" . htmlspecialchars($job['job_type']) . "'");

  }
} catch (Exception $e) {
  // if an exception occurs, we still want to remove the job from the queue, even though we
  // may not have inserted in any valid data
  $runtime_exception = $e;
}

// delete job
$q = db_master()->prepare("UPDATE jobs SET is_executed=1,is_executing=0,is_error=?,executed_at=NOW() WHERE id=?");
$job['is_error'] = ($runtime_exception === null ? 0 : 1);
$q->execute(array($job['is_error'], $job['id']));

// if this is a standard failure-enabled account, then disable the job if it has failed repeatedly,
// or reset the failure count if it's not failed this time
$account_data = false;
foreach (account_data_grouped() as $label => $group) {
  foreach ($group as $exchange => $data) {
    if ($job['job_type'] == $exchange) {
      $account_data = $data;
      $account_data['exchange'] = $exchange;
      break;
    }
  }
}
if (!$account_data) {
  if ($job['job_type'] == 'securities_havelock') {
    $account_data = array('failure' => true, 'table' => 'securities_havelock', 'exchange' => 'securities_havelock', 'label' => 'ticker', 'labels' => 'tickers', 'title' => $job['arg_id']);
  }
  if ($job['job_type'] == 'securities_crypto-trade') {
    $account_data = array('failure' => true, 'table' => 'securities_cryptotrade', 'exchange' => 'securities_cryptotrade', 'label' => 'ticker', 'labels' => 'tickers', 'title' => $job['arg_id']);
  }
}
if ($account_data && $account_data['failure']) {
  $failing_table = $account_data['table'];

  // failed?
  if ($runtime_exception !== null) {
    // don't count CloudFlare as a failure
    if ($runtime_exception instanceof CloudFlareException || $runtime_exception instanceof \Openclerk\Apis\CloudFlareException) {
      crypto_log("Not increasing failure count: was a CloudFlareException");
    } else if ($runtime_exception instanceof IncapsulaException || $runtime_exception instanceof \Openclerk\Apis\IncapsulaException) {
      crypto_log("Not increasing failure count: was a IncapsulaException");
    } else if ($runtime_exception instanceof BlockchainException || $runtime_exception instanceof \Core\BlockchainException) {
      crypto_log("Not increasing failure count: was a BlockchainException");
    } else {
      $q = db_master()->prepare("UPDATE $failing_table SET failures=failures+1,first_failure=IF(ISNULL(first_failure), NOW(), first_failure) WHERE id=?");
      $q->execute(array($job['arg_id']));
      crypto_log("Increasing account failure count");
    }

    $user = get_user($job['user_id']);
    if (!$user) {
      crypto_log("Warning: No user " . $job['user_id'] . " found");

    } else {

      // failed too many times?
      $q = db_master()->prepare("SELECT * FROM $failing_table WHERE id=? LIMIT 1");
      $q->execute(array($job['arg_id']));
      $account = $q->fetch();
      crypto_log("Current account failure count: " . number_format($account['failures']));

      if ($account['failures'] >= get_premium_value($user, 'max_failures')) {
        // disable it and send an email
        $q = db_master()->prepare("UPDATE $failing_table SET is_disabled=1 WHERE id=?");
        $q->execute(array($job['arg_id']));

        if ($user['email'] && !$account['is_disabled'] /* don't send the same email multiple times */) {
          send_user_email($user, "failure", array(
            "name" => ($user['name'] ? $user['name'] : $user['email']),
            "exchange" => get_exchange_name($account_data['exchange']),
            "label" => $account_data['label'],
            "labels" => $account_data['labels'],
            "failures" => number_format($account['failures']),
            "message" => $runtime_exception->getMessage(),
            "length" => recent_format(strtotime($account['first_failure']), "", ""),
            "title" => (isset($account['title']) && $account['title']) ? "\"" . $account['title'] . "\"" : "untitled",
            "url" => absolute_url(url_for("wizard_accounts")),
          ));
          crypto_log("Sent failure e-mail to " . htmlspecialchars($user['email']) . ".");
        }

      }

    }

  } else {

    // reset the failure counter
    $q = db_master()->prepare("UPDATE $failing_table SET failures=0 WHERE id=?");
    $q->execute(array($job['arg_id']));

  }
}

if (defined('BATCH_JOB_START')) {
  $end_time = microtime(true);
  $time_diff = ($end_time - BATCH_JOB_START) * 1000;
  crypto_log("Executed in " . number_format($time_diff, 2) . " ms.");

  // issue #135: capture job performance metrics
  performance_metrics_job_complete($job, $runtime_exception);
}

// rethrow exception if necessary
if ($runtime_exception !== null) {
  throw new WrappedJobException($runtime_exception, $job['id']);
}

echo "\n<li>Job successful.";

batch_footer();
