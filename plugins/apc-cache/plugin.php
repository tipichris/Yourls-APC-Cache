<?php
/*
Plugin Name: APC Cache
Plugin URI: http://virgingroupdigital.wordpress.com
Description: Caches most database traffic at the expense of some accuracy
Version: 0.3.2
Author: Ian Barber <ian.barber@gmail.com>
Author URI: http://phpir.com/
*/

// Verify APC is installed, suggested by @ozh
if( !function_exists( 'apc_exists' ) ) {
   yourls_die( 'This plugin requires the APC extension: http://pecl.php.net/package/APC' );
}

// keys for APC storage
if(!defined('APC_CACHE_ID')) {
	define('APC_CACHE_ID', 'ycache-');
}
define('APC_CACHE_LOG_INDEX', APC_CACHE_ID . 'log_index');
define('APC_CACHE_LOG_TIMER', APC_CACHE_ID . 'log_timer');
define('APC_CACHE_LOG_UPDATE_LOCK', APC_CACHE_ID . 'log_update_lock');
define('APC_CACHE_CLICK_INDEX', APC_CACHE_ID . 'click_index');
define('APC_CACHE_CLICK_TIMER', APC_CACHE_ID . 'click_timer');
define('APC_CACHE_CLICK_KEY_PREFIX', APC_CACHE_ID . 'clicks-');
define('APC_CACHE_CLICK_UPDATE_LOCK', APC_CACHE_ID . 'click_update_lock');
define('APC_CACHE_KEYWORD_PREFIX', APC_CACHE_ID . 'keyword-');
define('APC_CACHE_ALL_OPTIONS', APC_CACHE_ID . 'get_all_options');
define('APC_CACHE_YOURLS_INSTALLED', APC_CACHE_ID . 'yourls_installed');
define('APC_CACHE_BACKOFF_KEY', APC_CACHE_ID . 'backoff');
define('APC_CACHE_CLICK_INDEX_LOCK', APC_CACHE_ID . 'click_index_lock');

// configurable options
if(!defined('APC_CACHE_WRITE_TIMEOUT')) {
	define('APC_CACHE_WRITE_TIMEOUT', 120);
}
if(!defined('APC_CACHE_READ_TIMEOUT')) {
	define('APC_CACHE_READ_TIMEOUT', 3600);
}
if(!defined('APC_CACHE_LONG_TIMEOUT')) {
	define('APC_CACHE_LONG_TIMEOUT', 86400);
}
if(!defined('APC_CACHE_MAX_LOAD')) {
	define('APC_CACHE_MAX_LOAD', 0.7);
}
if(!defined('APC_CACHE_MAX_UPDATES')) {
	define('APC_CACHE_MAX_UPDATES', 200);
}
if(!defined('APC_CACHE_MAX_CLICKS')) {
	define('APC_CACHE_MAX_CLICKS', 30);
}
if(!defined('APC_CACHE_BACKOFF_TIME')) {
	define('APC_CACHE_BACKOFF_TIME', 30);
}
if(!defined('APC_CACHE_WRITE_HARD_TIMEOUT')) {
	define('APC_CACHE_WRITE_HARD_TIMEOUT', 600);
}
if(!defined('APC_CACHE_LOCK_TIMEOUT')) {
	define('APC_CACHE_LOCK_TIMEOUT', 30);
}
if(!defined('APC_CACHE_API_USER')) {
	define('APC_CACHE_API_USER', '');
}

yourls_add_action( 'pre_get_keyword', 'apc_cache_pre_get_keyword' );
yourls_add_filter( 'get_keyword_infos', 'apc_cache_get_keyword_infos' );
if(!defined('APC_CACHE_SKIP_CLICKTRACK')) {
	yourls_add_filter( 'shunt_update_clicks', 'apc_cache_shunt_update_clicks' );
	yourls_add_filter( 'shunt_log_redirect', 'apc_cache_shunt_log_redirect' );
}
if(defined('APC_CACHE_REDIRECT_FIRST') && APC_CACHE_REDIRECT_FIRST) {
	// set a very low priority to ensure any other plugins hooking here run first,
	// as we die at the end of apc_cache_redirect_shorturl
	yourls_add_action( 'redirect_shorturl', 'apc_cache_redirect_shorturl', 999);
}
yourls_add_filter( 'shunt_all_options', 'apc_cache_shunt_all_options' );
yourls_add_filter( 'get_all_options', 'apc_cache_get_all_options' );
yourls_add_action( 'add_option', 'apc_cache_option_change' );
yourls_add_action( 'delete_option', 'apc_cache_option_change' );
yourls_add_action( 'update_option', 'apc_cache_option_change' );
yourls_add_filter( 'edit_link', 'apc_cache_edit_link' );
yourls_add_filter( 'api_actions', 'apc_cache_api_filter' );

/**
 * Return cached options is available
 *
 * @param bool $false 
 * @return bool true
 */
function apc_cache_shunt_all_options($false) {
	global $ydb; 
	
	$key = APC_CACHE_ALL_OPTIONS; 
	if(apc_exists($key)) {
		$ydb->option = apc_fetch($key);
		$ydb->installed = apc_fetch(APC_CACHE_YOURLS_INSTALLED);
		return true;
	} 
	
	return false;
}

/**
 * Cache all_options data. 
 *
 * @param array $options 
 * @return array options
 */
function apc_cache_get_all_options($option) {
	apc_store(APC_CACHE_ALL_OPTIONS, $option, APC_CACHE_READ_TIMEOUT);
	// Set timeout on installed property twice as long as the options as otherwise there could be a split second gap
	apc_store(APC_CACHE_YOURLS_INSTALLED, true, (2 * APC_CACHE_READ_TIMEOUT));
	return $option;
}

/**
 * Clear the options cache if an option is altered
 * This covers changes to plugins too
 *
 * @param string $plugin 
 */
function apc_cache_option_change($args) {
	apc_delete(APC_CACHE_ALL_OPTIONS);
}

/**
 * If the URL data is in the cache, stick it back into the global DB object. 
 * 
 * @param string $args
 */
function apc_cache_pre_get_keyword($args) {
	global $ydb;
	$keyword = $args[0];
	$use_cache = isset($args[1]) ? $args[1] : true;
	
	// Lookup in cache
	if($use_cache && apc_exists(apc_cache_get_keyword_key($keyword))) {
		$ydb->infos[$keyword] = apc_fetch(apc_cache_get_keyword_key($keyword)); 	
	}
}

/**
 * Store the keyword info in the cache
 * 
 * @param array $info
 * @param string $keyword
 */
function apc_cache_get_keyword_infos($info, $keyword) {
	// Store in cache
	apc_store(apc_cache_get_keyword_key($keyword), $info, APC_CACHE_READ_TIMEOUT);
	return $info;
}

/**
 * Delete a cache entry for a keyword if that keyword is edited.
 * 
 * @param array $return
 * @param string $url
 * @param string $keyword
 * @param string $newkeyword
 * @param string $title
 * @param bool $new_url_already_there
 * @param bool $keyword_is_ok
 */
function apc_cache_edit_link( $return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok ) {
	if($return['status'] != 'fail') {
		apc_delete(apc_cache_get_keyword_key($keyword));
	}
	return $return;
}

/**
 * Update the number of clicks in a performant manner.  This manner of storing does
 * mean we are pretty much guaranteed to lose a few clicks. 
 * 
 * @param string $keyword
 */
function apc_cache_shunt_update_clicks($false, $keyword) {
	
	// initalize the timer. 
	if(!apc_exists(APC_CACHE_CLICK_TIMER)) {
		apc_add(APC_CACHE_CLICK_TIMER, time());
	}
	
	if(defined('APC_CACHE_STATS_SHUNT')) {
		if(APC_CACHE_STATS_SHUNT == "drop") {
			return true;
		} else if(APC_CACHE_STATS_SHUNT == "none"){
			return false;
		}
	} 
	
	$keyword = yourls_sanitize_string( $keyword );
	$key = APC_CACHE_CLICK_KEY_PREFIX . $keyword;
	
	// Store in cache
	$added = false; 
	$clicks = 1;
	if(!apc_exists($key)) {
		$added = apc_add($key, $clicks);
	}
	if(!$added) {
		$clicks = apc_cache_key_increment($key);
	}
  
	/* we need to keep a record of which keywords we have
	 * data cached for. We do this in an associative array
	 * stored at APC_CACHE_CLICK_INDEX, with keyword as the keyword
	 */
	$idxkey = APC_CACHE_CLICK_INDEX;
	apc_cache_lock_click_index();
	if(apc_exists($idxkey)) {
		$clickindex = apc_fetch($idxkey);
	} else {
		$clickindex = array();
	}
	$clickindex[$keyword] = 1;
	apc_store ( $idxkey, $clickindex);
	apc_cache_unlock_click_index();
	
	if(apc_cache_write_needed('click', $clicks)) {
		apc_cache_write_clicks();
	}
	
	return true;
}

/**
 * write any cached clicks out to the database 
 */
function apc_cache_write_clicks() {
	global $ydb;
	apc_cache_debug("write_clicks: Writing clicks to database");
	$updates = 0;
	// set up a lock so that another hit doesn't start writing too
	if(!apc_add(APC_CACHE_CLICK_UPDATE_LOCK, 1, APC_CACHE_LOCK_TIMEOUT)) {
		apc_cache_debug("write_clicks: Could not lock the click index. Abandoning write", true);
		return $updates;
	}
	
	if(apc_exists(APC_CACHE_CLICK_INDEX)) {
		apc_cache_lock_click_index();
		$clickindex = apc_fetch(APC_CACHE_CLICK_INDEX);
		if($clickindex === false || !apc_delete(APC_CACHE_CLICK_INDEX)) {
			// if apc_delete fails it's because the key went away. We probably have a race condition
			apc_cache_unlock_click_index();
			apc_cache_debug("write_clicks: Index key disappeared. Abandoning write", true);
			return $updates; 
		}
		apc_cache_unlock_click_index();

		/* as long as the tables support transactions, it's much faster to wrap all the updates
		* up into a single transaction. Reduces the overhead of starting a transaction for each
		* query. The down side is that if one query errors we'll loose the log
		*/
		$ydb->query("START TRANSACTION");
		foreach ($clickindex as $keyword => $z) {
			$key = APC_CACHE_CLICK_KEY_PREFIX . $keyword;
			$value = 0;
			if(!apc_exists($key)) {
				apc_cache_debug("write_clicks: Click key $key dissappeared. Possible data loss!", true);
				continue;
			}
			$value += apc_cache_key_zero($key);
			apc_cache_debug("write_clicks: Adding $value clicks for $keyword");
			// Write value to DB
			$ydb->query("UPDATE `" . 
							YOURLS_DB_TABLE_URL. 
						"` SET `clicks` = clicks + " . $value . 
						" WHERE `keyword` = '" . $keyword . "'");
			$updates++;
		}
		apc_cache_debug("write_clicks: Committing changes");
		$ydb->query("COMMIT");
		apc_store(APC_CACHE_CLICK_TIMER, time());
	}
	apc_delete(APC_CACHE_CLICK_UPDATE_LOCK);
	apc_cache_debug("write_clicks: Updated click records for $updates URLs");
	return $updates;
}

/**
 * Update the log in a performant way. There is a reasonable chance of losing a few log entries. 
 * This is a good trade off for us, but may not be for everyone. 
 *
 * @param string $keyword
 */
function apc_cache_shunt_log_redirect($false, $keyword) {

	if(defined('APC_CACHE_STATS_SHUNT')) {
		if(APC_CACHE_STATS_SHUNT == "drop") {
			return true;
		} else if(APC_CACHE_STATS_SHUNT == "none"){
			return false;
		}
	}
	// respect setting in YOURLS_NOSTATS. Why you'd want to enable the plugin and 
	// set YOURLS_NOSTATS true I don't know ;)
	if ( !yourls_do_log_redirect() )
		return true;

	// Initialise the time.
	if(!apc_exists(APC_CACHE_LOG_TIMER)) {
		apc_add(APC_CACHE_LOG_TIMER, time());
	}
	$ip = yourls_get_IP();
	$args = array(
		date( 'Y-m-d H:i:s' ),
		yourls_sanitize_string( $keyword ),
		( isset( $_SERVER['HTTP_REFERER'] ) ? yourls_sanitize_url( $_SERVER['HTTP_REFERER'] ) : 'direct' ),
		yourls_get_user_agent(),
		$ip,
		yourls_geo_ip_to_countrycode( $ip )
	);
	
	// Separated out the calls to make a bit more readable here
	$key = APC_CACHE_LOG_INDEX;
	$logindex = 0;
	$added = false;
	
	if(!apc_exists($key)) {
		$added = apc_add($key, 0);
	} 
	

	$logindex = apc_cache_key_increment($key);

	
	// We now have a reserved logindex, so lets cache
	apc_store(apc_cache_get_logindex($logindex), $args, APC_CACHE_LONG_TIMEOUT);
	
	// If we've been caching for over a certain amount do write
	if(apc_cache_write_needed('log')) {
		// We can add, so lets flush the log cache
		apc_cache_write_log();
	} 
	
	return true;
}

/**
 * write any cached log entries out to the database 
 */
function apc_cache_write_log() {
	global $ydb;
	$updates = 0;
	// set up a lock so that another hit doesn't start writing too
	if(!apc_add(APC_CACHE_LOG_UPDATE_LOCK, 1, APC_CACHE_LOCK_TIMEOUT)) {
		apc_cache_debug("write_log: Could not lock the log index. Abandoning write", true);
		return $updates;
	}
	apc_cache_debug("write_log: Writing log to database");

	$key = APC_CACHE_LOG_INDEX;
	$index = apc_fetch($key);
	if($index === false) {
		apc_cache_debug("write_log: key $key has disappeared. Abandoning write.");
		apc_store(APC_CACHE_LOG_TIMER, time());
		apc_delete(APC_CACHE_LOG_UPDATE_LOCK);
		return $updates;
	}
	$fetched = 0;
	$n = 0;
	$loop = true;
	$values = array();
	
	// Retrieve all items and reset the counter
	while($loop) {
		for($i = $fetched+1; $i <= $index; $i++) {
			$row = apc_fetch(apc_cache_get_logindex($i));
			if($row === false) {
				apc_cache_debug("write_log: log entry " . apc_cache_get_logindex($i) . " disappeared. Possible data loss!!", true);
			} else {
				$values[] = $row;
			}
		}
		
		$fetched = $index;
		$n++;
		
		if(apc_cas($key, $index, 0)) {
			$loop = false;
		} else {
			usleep(500);
			$index = apc_fetch($key);
		}
	}
	apc_cache_debug("write_log: $fetched log entries retrieved; index reset after $n tries");
	// Insert all log message - we're assuming input filtering happened earlier
	$query = "";

	foreach($values as $value) {
		if(!is_array($value)) {
		  apc_cache_debug("write_log: log row is not an array. Skipping");
		  continue;
		}
		if(strlen($query)) {
			$query .= ",";
		}
		$row = "('" . 
			$value[0] . "', '" . 
			$value[1] . "', '" . 
			$value[2] . "', '" . 
			$value[3] . "', '" . 
			$value[4] . "', '" . 
			$value[5] . "')";
		apc_cache_debug("write_log: row: $row");
		$query .= $row;
		$updates++;
	}
	$ydb->query( "INSERT INTO `" . YOURLS_DB_TABLE_LOG . "` 
				(click_time, shorturl, referrer, user_agent, ip_address, country_code)
				VALUES " . $query);
	apc_store(APC_CACHE_LOG_TIMER, time());
	apc_delete(APC_CACHE_LOG_UPDATE_LOCK);
	apc_cache_debug("write_log: Added $updates entries to log");
	return $updates;

}

/**
 * Helper function to return a cache key for the log index.
 *
 * @param string $key 
 * @return string
 */
function apc_cache_get_logindex($key) {
	return APC_CACHE_LOG_INDEX . "-" . $key;
}

/**
 * Helper function to return a keyword key.
 *
 * @param string $key 
 * @return string
 */
function apc_cache_get_keyword_key($keyword) {
	return APC_CACHE_KEYWORD_PREFIX . $keyword;
}

/**
 * Helper function to do an atomic increment to a variable, 
 * 
 *
 * @param string $key 
 * @return void
 */
function apc_cache_key_increment($key) {
	$n = 1;
	while(!$result = apc_inc($key)) {
		usleep(500);
		$n++;
	}
	if($n > 1) apc_cache_debug("key_increment: took $n tries on key $key");
	return $result;
}

/**
 * Reset a key to 0 in a atomic manner
 *
 * @param string $key 
 * @return old value before the reset
 */
function apc_cache_key_zero($key) {
	$old = 0;
	$n = 1;
	$old = apc_fetch($key);
	if($old == 0) {
		return $old;
	}
	while(!apc_cas($key, $old, 0)) {
		usleep(500);
		$n++;
		$old = apc_fetch($key);
		if($old == 0) {
			apc_cache_debug("key_zero: Key zeroed by someone else. Try $n. Key $key");
			return $old;
		}
	}
	if($n > 1) apc_cache_debug("key_zero: Key $key zeroed from $old after $n tries");
	return $old;
}

/**
 * Helper function to manage a voluntary lock on APC_CACHE_CLICK_INDEX
 * 
 * @return true when locked
 */
function apc_cache_lock_click_index() {
	$n = 1;
	// we always unlock as soon as possilbe, so a TTL of 1 should be fine
	while(!apc_add(APC_CACHE_CLICK_INDEX_LOCK, 1, 1)) {
		$n++;
		usleep(500);
	} 
	if($n > 1) apc_cache_debug("lock_click_index: Locked click index in $n tries");
	return true;
}

/**
 * Helper function to unlock a voluntary lock on APC_CACHE_CLICK_INDEX
 * 
 * @return void
 */
function apc_cache_unlock_click_index() {
	apc_delete(APC_CACHE_CLICK_INDEX_LOCK);
}

/**
 * Send debug messages to PHP's error log
 *
 * @param string $msg
 * @param bool $important
 * @return void
 */
function apc_cache_debug ($msg, $important=false) {
	if ($important || (defined('APC_CACHE_DEBUG') && APC_CACHE_DEBUG)) { 
		error_log("yourls_apc_cache: " . $msg);
	}
}

/**
 * Check if the server load is above our maximum threshold for doing DB writes
 * 
 * @return bool true if load exceeds threshold, false otherwise
 */
function apc_cache_load_too_high() {
	if(APC_CACHE_MAX_LOAD == 0)
		// APC_CACHE_MAX_LOAD of 0 means don't do load check
		return false;
	if (stristr(PHP_OS, 'win'))
		// can't get load on Windows, so just assume it's OK
		return false;
	$load = sys_getloadavg();
	if ($load[0] < APC_CACHE_MAX_LOAD) 
		return false;
	return true;
}

/**
 * Count number of click updates that are cached
 * 
 * @return int number of keywords with cached clicks
 */
function apc_cache_click_updates_count() {
	$count = 0;
	if(apc_exists(APC_CACHE_CLICK_INDEX)) {
		$clickindex = apc_fetch(APC_CACHE_CLICK_INDEX);
		$count = count($clickindex);
	}
	return $count;
}


/**
 * Check if we need to do a write to DB yet
 * Considers time since last write, system load etc
 *
 * @param string $type either 'click' or 'log' 
 * @param int $clicks number of clicks cached for current URL
 * @return bool true if a DB write is due, false otherwise
 */
function apc_cache_write_needed($type, $clicks=0) {
		
	if($type == 'click') {
		$timerkey = APC_CACHE_CLICK_TIMER;
		$count = apc_cache_click_updates_count();
	} elseif ($type = 'log') {
		$timerkey = APC_CACHE_LOG_TIMER;
		$count = apc_fetch(APC_CACHE_LOG_INDEX);
	} else {
		return false;
	}
	if (empty($count)) $count = 0;
	apc_cache_debug("write_needed: Info: $count $type updates in cache");
	
	if (!empty($clicks)) apc_cache_debug("write_needed: Info: current URL has $clicks cached clicks");
	
	if(apc_exists($timerkey)) {
		$lastupdate = apc_fetch($timerkey);
		$elapsed = time() - $lastupdate;
		apc_cache_debug("write_needed: Info: Last $type write $elapsed seconds ago at " . strftime("%T" , $lastupdate));
		
		/**
		 * in the tests below APC_CACHE_WRITE_TIMEOUT of 0 means never do a write on the basis of
		 * time elapsed, APC_CACHE_MAX_UPDATES of 0 means never do a write on the basis of number 
		 * of queued updates, APC_CACHE_MAX_CLICKS of 0 means never write on the basis of the number
		 * clicks pending
		 **/
		 
		// if we reached APC_CACHE_WRITE_HARD_TIMEOUT force a write out no matter what
		if ( !empty(APC_CACHE_WRITE_TIMEOUT) && $elapsed > APC_CACHE_WRITE_HARD_TIMEOUT) {
			apc_cache_debug("write_needed: True: Reached hard timeout (" . APC_CACHE_WRITE_HARD_TIMEOUT ."). Forcing write for $type after $elapsed seconds");
			return true;
		}
		
		// if we've backed off because of server load, don't write
		if( apc_exists(APC_CACHE_BACKOFF_KEY)) {
			apc_cache_debug("write_needed: False: Won't do write for $type during backoff period");
			return false;
		}
		
		// have we either reached APC_CACHE_WRITE_TIMEOUT or exceeded APC_CACHE_MAX_UPDATES or APC_CACHE_MAX_CLICKS
		if(( !empty(APC_CACHE_WRITE_TIMEOUT) && $elapsed > APC_CACHE_WRITE_TIMEOUT )
		    || ( !empty(APC_CACHE_MAX_UPDATES) && $count > APC_CACHE_MAX_UPDATES )
		    || (!empty(APC_CACHE_MAX_CLICKS) && !empty($clicks) && $clicks > APC_CACHE_MAX_CLICKS) ) {
			// if server load is high, delay the write and set a backoff so we won't try again
			// for a short while
			if(apc_cache_load_too_high()) {
				apc_cache_debug("write_needed: False: System load too high. Won't try writing to database for $type", true);
				apc_add(APC_CACHE_BACKOFF_KEY, time(), APC_CACHE_BACKOFF_TIME);
				return false;
			}
			apc_cache_debug("write_needed: True: type: $type; count: $count; elapsed: $elapsed; clicks: $clicks; APC_CACHE_WRITE_TIMEOUT: " . APC_CACHE_WRITE_TIMEOUT . "; APC_CACHE_MAX_UPDATES: " . APC_CACHE_MAX_UPDATES . "; APC_CACHE_MAX_CLICKS: " . APC_CACHE_MAX_CLICKS);
			return true;
		}

		return false;
	}
	
	// The timer key went away. Better do an update to be safe
	apc_cache_debug("write_needed: True: reason: no $type timer found");
	return true;
	
}

/**
 * Add the flushcache method to the API
 *
 * @param array $api_action 
 * @return array $api_action
 */
function apc_cache_api_filter($api_actions) {
	$api_actions['flushcache'] = 'apc_cache_force_flush';
	return $api_actions;
}

/**
 * Force a write of both clicks and logs to the database
 *
 * @return array $return status of updates
 */
function apc_cache_force_flush() {
	/* APC_CACHE_API_USER of false means disable API. 
	 * APC_CACHE_API_USER of empty string means allow
	 * any user to use API. Otherwise only the specified 
	 * user is allowed
	 */
	$user = defined( 'YOURLS_USER' ) ? YOURLS_USER : '-1';
	if(APC_CACHE_API_USER === false) {
		apc_cache_debug("force_flush: Attempt to use API flushcache function whilst it is disabled. User: $user", true);
		$return = array(
			'simple'    => 'Error: The flushcache function is disabled',
			'message'   => 'Error: The flushcache function is disabled',
			'errorCode' => 403,
		);
	} 
	elseif(!empty(APC_CACHE_API_USER) && APC_CACHE_API_USER != $user) {
		apc_cache_debug("force_flush: Unauthorised attempt to use API flushcache function by $user", true);
		$return = array(
			'simple'    => 'Error: User not authorised to use the flushcache function',
			'message'   => 'Error: User not authorised to use the flushcache function',
			'errorCode' => 403,
		); 
	} else {
		apc_cache_debug("force_flush: Forcing write to database from API call");
		$start = microtime(true);
		$log_updates = apc_cache_write_log();
		$log_time = sprintf("%01.3f", 1000*(microtime(true) - $start));
		$click_updates = apc_cache_write_clicks();
		$click_time = sprintf("%01.3f", 1000*(microtime(true) - $start));
		$return = array(
			'clicksUpdated'   => $click_updates,
			'clickUpdateTime' => $click_time,
			'logsUpdated' => $log_updates,
			'logUpdateTime' => $log_time,
			'statusCode' => 200,
			'simple'     => "Updated clicks for $click_updates URLs in ${click_time} ms. Logged $log_updates hits in ${log_time} ms.",
			'message'    => 'Success',
		);
	}
	return $return;
}

/**
 * Replaces yourls_redirect. Does redirect first, then does logging and click
 * recording afterwards so that redirect is not delayed
 * This is somewhat fragile and may be broken by other plugins that hook on 
 * pre_redirect, redirect_location or redirect_code
 *
 */
function apc_cache_redirect_shorturl( $args ) {
	$code = defined('APC_CACHE_REDIRECT_FIRST_CODE')?APC_CACHE_REDIRECT_FIRST_CODE:301;
	$location = $args[0];
	$keyword = $args[1];
	yourls_do_action( 'pre_redirect', $location, $code );
	$location = yourls_apply_filter( 'redirect_location', $location, $code );
	$code     = yourls_apply_filter( 'redirect_code', $code, $location );
	// Redirect, either properly if possible, or via Javascript otherwise
	if( !headers_sent() ) {
		yourls_status_header( $code );
		header( "Location: $location" );
		// force the headers to be sent
		echo "Redirecting to $location\n";
		@ob_end_flush();
		@ob_flush();
		flush();
	} else {
		yourls_redirect_javascript( $location );
	}

	$start = microtime(true);
	// Update click count in main table
	$update_clicks = yourls_update_clicks( $keyword );

	// Update detailed log for stats
	$log_redirect = yourls_log_redirect( $keyword );
	$lapsed = sprintf("%01.3f", 1000*(microtime(true) - $start));
	apc_cache_debug("redirect_shorturl: Database updates took $lapsed ms after sending redirect");
	
	die();
}
