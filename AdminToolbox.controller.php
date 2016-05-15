<?php

/**
 * Admin Toolbox, little functions that are a big PITA
 *
 * @package Admin Toolbox
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://www.mozilla.org/MPL/1.1/.
 *
 * @version 1.0
 */

if (!defined('ELK'))
{
	die('No access...');
}

/**
 * Toolbox controller.
 *
 * This class handles requests that allow toolbox actions
 */
class Toolbox_Controller extends Action_Controller
{
	protected $db_title;

	/**
	 * Entry point function for Admin Toolbox, permission checks, Load dependencies
	 */
	public function pre_dispatch()
	{
		$db = database();

		// Load our subs file
		require_once(SUBSDIR . '/AdminToolbox.subs.php');

		// You absolutely must be an admin to be in here, and for some that still is not a good idea ;)
		isAllowedTo('admin_forum');

		// Need something so folks can see what we are saying since its important
		loadLanguage('AdminToolbox');
		loadTemplate('AdminToolbox');

		$this->db_title = $db->db_title() === 'MySQL';
	}

	/**
	 * AdminToolbox()
	 *
	 * Main entry point for the manage toolbox options
	 */
	public function action_index()
	{
		// Where do you want to go today?
		$subActions = array(
			'main' => array($this, 'action_main'),
			'inactive' => array($this, 'action_inactive'),
			'merge' => array($this, 'action_mergemembers'),
			'stats' => array($this, 'action_stats_recount'),
			'statsvalidate' => array($this, 'action_stats_validate'),
			'validate' => array($this, 'action_member_validate'),
		);

		// We like action, so lets get ready for some
		$action = new Action('');

		// Get the subAction, or just go to action_main
		$subAction = $action->initialize($subActions, 'main');

		// Finally go to where we want to go
		$action->dispatch($subAction);
	}

	/**
	 * Just display the main page for the toolbox
	 */
	public function action_main()
	{
		global $context, $txt;

		// Set a few things.
		$context['sub_template'] = 'toolbox_main';
		$context['admintoolbox_database'] = $this->db_title;
		$context['page_title'] = $txt['toolbox_title'];

		// If we have any messages to show
		if (isset($_GET['done']) && isset($txt['toolbox_' . $_GET['done']]))
		{
			$context['maintenance_finished'] = $txt['toolbox_' . $_GET['done']];
		}
		elseif (isset($_GET['error']) && isset($txt['toolbox_' . $_GET['error']]))
		{
			$context['maintenance_error'] = $txt['toolbox_' . $_GET['error']];
		}

		// A touch of JS
		loadJavascriptFile(array('suggest.js', 'toolbox.js'), array('defer' => true));
		addInlineJavascript('	// Auto suggest script
		var oToolBoxTo = new elk_ToolBox({
			sSelf: \'oToolBoxTo\',
			sSessionId: \'' . $context['session_id'] . '\',
			sSessionVar: \'' . $context['session_var'] . '\',
			sTextDeleteItem: \'' . $txt['autosuggest_delete_item'] . '\',
			sTextViewItem: \'' . $txt['autosuggest_view_item'] . '\',
			sToControlId: \'merge_to\',
			sContainer: \'merge_to_container\',
			sPostName: \'merge_to_id\',
			sSuggestId: \'to_suggest\',
			aToRecipients: [
			]
			});
		var oToolBoxFrom = new elk_ToolBox({
			sSelf: \'oToolBoxFrom\',
			sSessionId: \'' . $context['session_id'] . '\',
			sSessionVar: \'' . $context['session_var'] . '\',
			sTextDeleteItem: \'' . $txt['autosuggest_delete_item'] . '\',
			sTextViewItem: \'' . $txt['autosuggest_view_item'] . '\',
			sToControlId: \'merge_from\',
			sContainer: \'merge_from_container\',
			sPostName: \'merge_from_id\',
			sSuggestId: \'from_suggest\',
			aToRecipients: [
			]
		});', true);
	}

	/**
	 * Mark inactive users as having read everything
	 */
	public function action_inactive()
	{
		global $txt, $context, $modSettings, $db_prefix;

		$db = database();

		checkSession('request');

		// Set up to the context.
		$context['page_title'] = $txt['not_done_title'];
		$context['continue_countdown'] = 5;
		$context['continue_post_data'] = '';
		$context['continue_get_data'] = '';
		$context['sub_template'] = 'not_done';

		// Init our run
		$increment = 100;
		$start = !isset($_GET['start']) ? 0 : (int) $_GET['start'];
		$not_visited = time() - (!empty($_POST['inactive_days']) ? $_POST['inactive_days'] : 60) * 24 * 3600;

		// Ask for some extra time
		@set_time_limit(600);

		// Only run this query on the first loop
		if (!isset($_SESSION['boards']))
		{
			// First thing's first - get all the boards in the system
			$boards = atb_boards();

			// Save it for use in other loops
			$_SESSION['boards'] = $boards;
		}

		$boards = $_SESSION['boards'];
		$inserts = array();

		// Load a group of members from the log_topics table who have not been active  ...
		list($members, $total_rows) = atb_members_logtopics($not_visited, $increment);

		// Build the inserts for this bunch
		foreach ($members as $member)
		{
			// Mark the boards as read for this member
			foreach ($boards as $board)
			{
				$inserts[] = array($modSettings['maxMsgID'], $member, $board);
			}
		}

		// Do the updates
		atb_mark_read($inserts);

		// And now remove the useless log_topics data, for these members, since these inactive members just read everything
		atb_mark_log_topics($members);

		// Continue?
		if ($total_rows == $increment)
		{
			$start += $increment;
			$context['continue_get_data'] = '?action=admin;area=toolbox;sa=inactive;start=' . $start . ';' . $context['session_var'] . '=' . $context['session_id'];
			$context['continue_percent'] = round(100 * ($start / $_SESSION['total_members']));

			// Really would like to keep running mr. apache
			if (function_exists('apache_reset_timeout'))
			{
				apache_reset_timeout();
			}

			return;
		}

		// Optimize the one table that should have gone down in size, assuming its not innodb of course
		ignore_user_abort(true);
		$real_prefix = preg_match('~^(`?)(.+?)\\1\\.(.*?)$~', $db_prefix, $match) === 1
			? $match[3]
			: $db_prefix;
		$db->db_optimize_table($real_prefix . 'log_topics');

		// All done
		unset($_SESSION['boards']);
		redirectexit('action=admin;area=toolbox;done=inactive');
	}

	/**
	 * Check for the condition where message/topic/registration data predates the stats data
	 * Needed to build some value for Hits and Most online for those earlier dates
	 * Zero, Current Average, or Balanced options
	 */
	public function action_stats_validate()
	{
		global $txt, $context;

		// Not getting hacky with it are ya?
		if ($this->db_title !== 'MySQL')
		{
			redirectexit('action=admin;area=toolbox;');
		}

		// The oldest message date
		$message_start_date = atb_oldest_message();

		// When the daily stats started, plus some totals in case we need them
		$stats = atb_stats_start();

		// For the averages option
		$total_days_up = ceil((time() - strtotime($stats['stat_start_date'])) / (60 * 60 * 24));
		$stats['total_days_up'] = $total_days_up;
		$stats['most_on'] = ceil($stats['most_on'] / $total_days_up);
		$stats['hits'] = ceil($stats['hits'] / $total_days_up);

		// Is there a notable datediff between the daily stats and message data?
		// Then we may need to "make some stuff up"tm to fill the gaps
		if (round((strtotime($stats['stat_start_date']) - strtotime($message_start_date)) / 86400) > 0)
		{
			// Get the existing monthly statistics data
			$stats_data = atb_load_monthly_stats($message_start_date);

			// Shortcuts
			$stats_data_hits = $stats_data['hits'];
			$stats_data_most_on = $stats_data['most_on'];
			$stats_data_delta = $stats_data['delta'];

			/*
			 * - If we have enough data, drop first and last months as its start up and shutdown noise (incomplete months) in the data
			 * - If data is very lagged, then add in a new entry point to influence the curve fit coefficents to minimize b intercept
			 * - With the data determine curve fit coefficients for the data line so we can use them to build other values, we are using exponetial
			 *   should you choose linear, you will need to change the equations in the statsrecount function
			 */
			$missing_months = count($stats_data_hits);
			if ($missing_months >= 4)
			{
				$stats_data_hits = array_slice($stats_data_hits, 1, count($stats_data_hits) - 2);
				if ($missing_months >= 12)
				{
					array_unshift($stats_data_hits, 1);
				}

				$stats_data_most_on = array_slice($stats_data_most_on, 1, count($stats_data_most_on) - 2);
				if ($missing_months >= 12)
				{
					array_unshift($stats_data_most_on, 1);
				}

				$stats_data_delta = array_slice($stats_data_delta, 1, count($stats_data_delta) - 2);
				if ($missing_months >= 12)
				{
					array_unshift($stats_data_delta, 0);
				}
			}

			$stats['most_on_coeff'] = ($this->_linear_regression($stats_data_delta, $stats_data_most_on, true));
			$stats['hits_coeff'] = ($this->_linear_regression($stats_data_delta, $stats_data_hits, true));
		}

		$stats['message_start_date'] = $message_start_date;
		$context['stats'] = $stats;
		$context['sub_template'] = 'toolbox_stats_rebuild';

		// Data rebuild Options, based on what we can do
		if ($total_days_up == 1)
		{
			$context['toolbox_rebuild_option'] = array(
				array('id' => 1, 'value' => 'bypass', 'name' => $txt['toolbox_skip'], 'desc' => $txt['toolbox_skip_desc']),
				array('id' => 2, 'value' => 'zero', 'name' => $txt['toolbox_zero'], 'desc' => $txt['toolbox_zero_desc']),
			);
		}
		else
		{
			$context['toolbox_rebuild_option'] = array(
				array('id' => 1, 'value' => 'bypass', 'name' => $txt['toolbox_skip'], 'desc' => $txt['toolbox_skip_desc']),
				array('id' => 2, 'value' => 'zero', 'name' => $txt['toolbox_zero'], 'desc' => $txt['toolbox_zero_desc']),
				array('id' => 3, 'value' => 'average', 'name' => $txt['toolbox_average'], 'desc' => $txt['toolbox_average_desc']),
				array('id' => 4, 'value' => 'balanced', 'name' => $txt['toolbox_balanced'], 'desc' => $txt['toolbox_balanced_desc']),
			);
		}
	}

	/**
	 * Best fit (least squares) linear regression for of $y = $m * $x + $b
	 * Optional exponential curve fit in the form of $y = $b * pow($m, $x)
	 *
	 * @param array[] $x array x-coords
	 * @param array[] $y array y-coords
	 * @param boolean $power
	 *
	 * @returns array() m=>slope, b=>intercept
	 */
	private function _linear_regression($x, $y, $power = false)
	{
		// Number of data points
		$n = count($x);

		// Arrays need to be the same size and have more than 1 point for the math to work
		if ($n != count($y) || $n == 1)
		{
			return array('m' => 0, 'b' => 0);
		}

		// Convert Y data to logs only if doing an exponential fit
		if ($power)
		{
			foreach ($y as $key => $value)
			{
				$y[$key] = log10($value);
			}
		}

		// Calculate sums
		$x_sum = array_sum($x);
		$y_sum = array_sum($y);
		$xx_sum = 0;
		$xy_sum = 0;

		// And the sum of the squares
		foreach ($x as $key => $value)
		{
			$xy_sum += ($value * $y[$key]);
			$xx_sum += ($value * $value);
		}

		// Slope aka 'm'
		$divisor = (($n * $xx_sum) - ($x_sum * $x_sum));
		if ($divisor == 0)
		{
			$m = 0;
		}
		else
		{
			$m = (($n * $xy_sum) - ($x_sum * $y_sum)) / $divisor;
		}

		// Intercept aka 'b'
		$b = ($y_sum - ($m * $x_sum)) / $n;

		// Adjust linear fit of log data back to power coefficients
		if ($power)
		{
			$m = pow(10, $m);
			$b = pow(10, $b);
		}

		// Return coefficients
		return array('m' => $m, 'b' => $b);
	}

	/**
	 * StatsRecount()
	 *
	 * Recount the daily posts and topics for the stats page
	 * Recount the daily users registered
	 * Updates the log activity table with the new counts
	 * *mysql only*, others are welcome to port it to other schemas ... some hints
	 *  - GROUP BY EXTRACT(YEAR_MONTH FROM needs to be done as two parts, year and then month for PostgreSQL
	 *  - TIME_TO_SEC needs to be changed
	 *  - ON DUPLICATE KEY UPDATE replaced with a loop of inserts and if it fails an update instead or other such
	 * misery
	 */
	public function action_stats_recount()
	{
		global $txt, $context, $modSettings, $db_prefix;

		$db = database();

		checkSession('request');

		// How did you get here, its implausible !
		if (DB_TYPE !== 'MySQL')
		{
			redirectexit('action=admin;area=toolbox;');
		}

		// init this pass
		$inserts = array();

		// Set up to the context.
		$context['page_title'] = $txt['not_done_title'];
		$context['continue_countdown'] = 3;
		$context['continue_post_data'] = '';
		$context['continue_get_data'] = '';
		$context['sub_template'] = 'not_done';

		// Only do these steps on the first loop
		if (!isset($_SESSION['start_date_int']))
		{
			// no data from the post page, you get bounced
			$stats = unserialize(stripslashes(htmlspecialchars_decode($_POST['stats_data'])));
			if (empty($stats))
			{
				redirectexit('action=admin;area=toolbox');
			}

			// keep each pass at the 4 month level ...
			$_SESSION['months_per_loop'] = 4;

			// choose how to rebuild any missing daily hits and most on-line
			$_POST['id_type'] = isset($_POST['id_type']) ? (int) $_POST['id_type'] : 1;
			switch ($_POST['id_type'])
			{
				case 1:
				default:
					$rebuild_method = 'bypass';
					$start_date = $stats['stat_start_date'];
					break;
				case 2:
					$rebuild_method = 'zero';
					$start_date = $stats['message_start_date'];
					break;
				case 3:
					$rebuild_method = 'average';
					$start_date = $stats['message_start_date'];
					break;
				case 4:
					$rebuild_method = 'balanced';
					$start_date = $stats['message_start_date'];
					break;
			}

			// Start building data from this point forward, Start at a month boundary to make the loops easy
			list($start_year, $start_month) = explode('-', $start_date);
			$_SESSION['start_date_str'] = $start_year . '-' . $start_month . '-' . '01';
			$_SESSION['start_date_int'] = strtotime($_SESSION['start_date_str']);

			// This is the actual start date from which we *do* count
			$_SESSION['original_date_str'] = $start_date;
			$_SESSION['original_date_int'] = strtotime($start_date);
			$stats['stat_start_date_int'] = strtotime($stats['stat_start_date']);

			// Account for the sql date not being the same as the server local date, wacky but I've seen it
			$request = $db->query('', '
				SELECT
					TIME_TO_SEC(timediff(NOW(), UTC_TIMESTAMP()))',
				array()
			);
			list($sql_offset) = $db->fetch_row($request);
			$db->free_result($request);
			$sql_offset = date('Z') - $sql_offset;

			// Total offset for the query time adjustment
			$total_offset = $sql_offset + ($modSettings['time_offset'] * 3600);

			// Save this, might need it ;)
			$_SESSION['stats'] = $stats;
			$_SESSION['total_offset'] = $total_offset;
			$_SESSION['rebuild_method'] = $rebuild_method;
		}

		// Loop Datesssssss
		$start_date = isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : $_SESSION['start_date_int'];
		$end_date = strtotime('+' . $_SESSION['months_per_loop'] . ' month -1 second', $start_date);
		$total_offset = $_SESSION['total_offset'];

		// Count the number of distinct topics and total messages for date range combo.
		$request = $db->query('', '
			SELECT
				poster_time, COUNT(t.id_topic) AS topics, COUNT(DISTINCT(id_msg)) AS posts,
				DATE(from_unixtime(poster_time + ' . $total_offset . ')) AS REALTIME
			FROM {db_prefix}messages AS m
				LEFT JOIN {db_prefix}topics AS t ON (t.id_first_msg = m.id_msg)
				LEFT JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
			WHERE poster_time >= {int:start_date} AND poster_time <= {int:end_date} AND b.count_posts = 0
			GROUP BY REALTIME
			ORDER BY REALTIME',
			array(
				'end_date' => $end_date + ($modSettings['time_offset'] * 3600),
				'start_date' => $start_date + ($modSettings['time_offset'] * 3600),
			)
		);
		while ($row = $db->fetch_assoc($request))
		{
			// Data is in the range we are collecting
			if (($row['poster_time'] + ($modSettings['time_offset'] * 3600)) >= $_SESSION['original_date_int'])
			{
				$date_id = $row['REALTIME'];

				if (isset($inserts[$date_id]))
				{
					$inserts[$date_id]['topics'] += $row['topics'];
					$inserts[$date_id]['messages'] += $row['posts'];
				}
				else
				{
					$inserts[$date_id] = array('topics' => (int) $row['topics'], 'messages' => (int) $row['posts'], 'date' => sprintf('\'%1$s\'', $date_id), 'registers' => 0);
				}
			}
		}
		$db->free_result($request);

		// Count the number of registrations per day
		$request = $db->query('', '
			SELECT
				count(is_activated) AS registers, date_registered
			FROM {db_prefix}members
			WHERE is_activated = {int:activated}
				AND date_registered >= {int:start_date}
				AND date_registered <= {int:end_date}
			GROUP BY date_registered',
			array(
				'activated' => 1,
				'end_date' => $end_date + $modSettings['time_offset'],
				'start_date' => $start_date + $modSettings['time_offset'],
			)
		);
		while ($row = $db->fetch_assoc($request))
		{
			// Data is in the range we are collecting
			if ($row['date_registered'] >= $_SESSION['original_date_int'])
			{
				// keep those day boundarys our you will have a mess
				$date_id = strftime('%Y-%m-%d', $row['date_registered'] - $modSettings['time_offset']);
				if (isset($inserts[$date_id]))
				{
					$inserts[$date_id]['registers'] += $row['registers'];
				}
				else
				{
					$inserts[$date_id] = array('topics' => 0, 'messages' => 0, 'date' => sprintf('\'%1$s\'', $date_id), 'registers' => (int) $row['registers']);
				}
			}
		}
		$db->free_result($request);

		// Thats all the "real" data we can rebuild, now lets fill in the hits/most data based on rebuild options
		$temp_time = $start_date;
		$end_time = $end_date;
		while ($end_time >= $temp_time)
		{
			$start_time = $temp_time;
			$array_time = strftime('%Y-%m-%d', $start_time);
			if ($start_time >= $_SESSION['original_date_int'] && (isset($inserts[$array_time])))
			{
				// Based on user choice calculate (nice way to say take a guess) the daily hits and most on line data
				switch ($_SESSION['rebuild_method'])
				{
					case 'average':
						$hits = $_SESSION['stats']['hits'];
						$most = $_SESSION['stats']['most_on'];
						break;
					case 'balanced':
						// As the curve fit approaches the real data points, the curve will begin to expand rapidly.
						// limiter is a linear fit based on the averages data to prevent this fly away and help the blend point of the
						// exponential curve in to the existing data.  I'd draw a picture here but ascii art is not my thing :)
						// but we are estimating missing data here, lies, damn lies and statistics! ;)
						$limiter = abs(((($start_date - $_SESSION['original_date_int'])) / 86400) / ((time() - $_SESSION['original_date_int']) / 86400));
						$x = round(($start_time - $_SESSION['original_date_int']) / 86400);
						$hits = min(floor($_SESSION['stats']['hits_coeff']['b'] * pow($_SESSION['stats']['hits_coeff']['m'], $x)), floor($_SESSION['stats']['hits'] * $limiter));
						$most = min(floor($_SESSION['stats']['most_on_coeff']['b'] * pow($_SESSION['stats']['most_on_coeff']['m'], $x)), floor($_SESSION['stats']['most_on'] * $limiter));
						$most = (empty($most))
							? 1
							: (int) $most;
						$hits = (empty($hits))
							? 1
							: (int) $hits;
						break;
					case 'zero':
					default:
						$hits = 0;
						$most = 0;
						break;
				}
				$inserts[$array_time] = array_merge($inserts[$array_time], array('hits' => $hits, 'most_on' => $most));
			}

			// Increment the date
			$temp_time = strtotime('+1 day', $start_time);
		}

		// All the data has been magically sanitised, while it was built, so create the insert values
		ksort($inserts);
		$insertRows = array();
		foreach ($inserts as $dataRow)
		{
			$insertRows[] = '(' . implode(',', $dataRow) . ')';
		}

		// We have data now to insert / update ....
		if (!empty($insertRows))
		{
			// Slam-A-Jama, all the inserts and updates in one big chunk, compliments of ON DUPLICATE KEY UPDATE, blissfully mysql only
			$db->query('', '
				INSERT INTO ' . $db_prefix . 'log_activity ' .
				'(`' . implode('`, `', array('topics', 'posts', 'date', 'registers', 'hits', 'most_on')) . '`)
				VALUES ' . implode(', ', $insertRows) .
				' ON DUPLICATE KEY UPDATE `topics` = VALUES(topics), `posts` = VALUES(posts), `registers` = VALUES(registers)',
				array(
					'security_override' => true,
					'db_error_skip' => false,
				)
			);
		}

		// Continue?
		$context['continue_percent'] = round(100 * (max(0, (($start_date - $_SESSION['original_date_int'])) / 86400) / ((time() - $_SESSION['original_date_int']) / 86400)));
		if ($start_date <= time())
		{
			$_GET['start_date'] = strtotime('+' . $_SESSION['months_per_loop'] . ' month', $start_date);
			$context['continue_get_data'] = '?action=admin;area=toolbox;sa=stats;start_date=' . $_GET['start_date'] . ';' . $context['session_var'] . '=' . $context['session_id'];

			if (function_exists('apache_reset_timeout'))
			{
				apache_reset_timeout();
			}

			return;
		}

		// All done
		unset($_SESSION['start_date_str'], $_SESSION['start_date_int'], $_SESSION['original_date_str'], $_SESSION['original_date_int'], $_SESSION['stats'], $_SESSION['rebuild_method'], $_SESSION['total_offset']);

		// Although not necessary some will like this table to remain in order so it looks good in phpmyadmin
		$db->query('', '
			ALTER TABLE {db_prefix}log_activity
			ORDER BY date',
			array()
		);

		redirectexit('action=admin;area=toolbox;done=stats');
	}

	/**
	 * MergeMembersValidate()
	 *
	 * Support function for merge users
	 * Validate the to and from users as existing and other validity checks
	 * Prepares context for display so user can approve the merge
	 */
	public function action_member_validate()
	{
		global $user_info, $user_profile, $context, $txt;

		checkSession();

		// Sanitize as needed, the _id post vars are set by the autosuggest script, if found move them to normal post
		$autosuggest_merge_to = empty($_POST['merge_to_id']) ? 0 : (int) $_POST['merge_to_id'];
		$autosuggest_merge_from = empty($_POST['merge_from_id']) ? 0 : (int) $_POST['merge_from_id'];

		// Did they use the search icon -or- enter a number directly in to the box or some combo there off ...
		if (empty($autosuggest_merge_to) || empty($autosuggest_merge_from))
		{
			// The post fields are obfuscated to prevent the browser from auto populating them, so we need to go find them
			reset($_POST);
			$count = 0;
			$merge_to = '';
			$merge_from = '';
			foreach ($_POST as $key => $value)
			{
				if (strpos($key, 'dummy_') !== false)
				{
					if ($count == 0)
					{
						$merge_to = Util::htmltrim($value);
					}
					else
					{
						$merge_from = Util::htmltrim($value);
					}
					$count++;
				}

				if ($count > 1)
				{
					break;
				}
			}

			// If not autosuggest populated, and we found the post field, use it
			if (empty($autosuggest_merge_to))
			{
				$merge_to = ($merge_to != '') ? $merge_to : 0;
			}
			if (empty($autosuggest_merge_from))
			{
				$merge_from = ($merge_from != '') ? $merge_from : 0;
			}

			// Supplied numbers did they, then assume they are userid's
			if (!empty($merge_from) && is_numeric($merge_from))
			{
				$autosuggest_merge_from = (int) $merge_from;
			}
			if (!empty($merge_to) && is_numeric($merge_to))
			{
				$autosuggest_merge_to = (int) $merge_to;
			}

			// Perhaps some text instead, then we search on the name to get the member id
			if (empty($autosuggest_merge_from) || empty($autosuggest_merge_to))
			{
				$query = 'real_name = ';
				$query .= (!empty($merge_to)) ? "'$merge_to'" : '';
				$query .= (!empty($merge_from) && !empty($merge_to)) ? " OR real_name = '$merge_from'" : (!empty($merge_from) ? "'$merge_from'" : '');
				$query_limit = (!empty($merge_from) && !empty($merge_to)) ? 3 : 2;

				// validate these are member names
				list($autosuggest_merge_to, $autosuggest_merge_from) = atb_validate_member_names($merge_to, $merge_from, $query_limit, $query);
			}
		}

		// Validate whatever we found, first you simply can't do this to a zero or blank
		if (empty($autosuggest_merge_to) || empty($autosuggest_merge_from))
		{
			redirectexit('action=admin;area=toolbox;error=zeroid');
		}

		// Not a good idea with the admin account either
		if (($autosuggest_merge_to == 1) || ($autosuggest_merge_from == 1))
		{
			redirectexit('action=admin;area=toolbox;error=adminid');
		}

		// And it cant be the same id
		if ($autosuggest_merge_to == $autosuggest_merge_from)
		{
			redirectexit('action=admin;area=toolbox;error=sameid');
		}

		// And these members must exist
		$check = loadMemberData(array($autosuggest_merge_to, $autosuggest_merge_from), false, 'minimal');
		if (empty($check) || count($check) != 2)
		{
			redirectexit('action=admin;area=toolbox;error=badid');
		}

		// And you can't delete the ID you are currently using, moron
		if (isset($_POST['deluser']) && ($autosuggest_merge_from == $user_info['id']))
		{
			redirectexit('action=admin;area=toolbox;error=baddelete');
		}

		// Data looks valid, so lets make them hit enter to continue ... we want to be sure about this !
		$context['page_title'] = $txt['toolbox_mergeuser_check'];
		$context['merge_to'] = $user_profile[$autosuggest_merge_to];
		$context['merge_from'] = $user_profile[$autosuggest_merge_from];
		$context['adjustuser'] = isset($_POST['adjustuser']) ? 1 : 0;
		$context['deluser'] = isset($_POST['deluser']) ? 1 : 0;
		$context['sub_template'] = 'toolbox_validate';
	}

	/**
	 * MergeTwoUsers()
	 *
	 * Merge two users ids in to a single account
	 * Optionally remove the source user
	 * Optionally merge key profile information
	 * Call _RecountMemberPosts on completion
	 */
	public function action_mergemembers()
	{
		global $txt, $context, $sourcedir;

		checkSession('request');

		// Set up to the context.
		$context['page_title'] = $txt['not_done_title'];
		$context['continue_countdown'] = 3;
		$context['continue_post_data'] = '';
		$context['continue_get_data'] = '';
		$context['sub_template'] = 'not_done';

		// Init our run
		$name = '';
		$steps = !isset($_SESSION['steps'])
			? 9
			: (int) $_SESSION['steps'];
		$step = !isset($_GET['step'])
			? 0
			: (int) $_GET['step'];

		// Ask for some extra time, never hurts ;)
		@set_time_limit(600);

		// Only need to do this at the start of our run
		if (!isset($_SESSION['dstid'], $_SESSION['srcid']))
		{
			$_POST['merge_to'] = empty($_POST['merge_to']) ? 0 : (int) $_POST['merge_to'];
			$_POST['merge_from'] = empty($_POST['merge_from']) ? 0 : (int) $_POST['merge_from'];

			// We support *some* addons, lets see if they are installed or not
			$addons_installed = $this->_toolbox_check_mods();

			// Add any extra steps to our counter so we can show a reflective progress bar
			$steps += count($addons_installed);

			// Set it for use in other loops
			$_SESSION['addons_installed'] = $addons_installed;
			$_SESSION['steps'] = $steps;
			$_SESSION['dstid'] = $_POST['merge_to'];
			$_SESSION['srcid'] = $_POST['merge_from'];
			$_SESSION['deluser'] = (int) $_POST['deluser'];
			$_SESSION['adjustuser'] = (int) $_POST['adjustuser'];
		}

		// lets mergerize the bums !!
		$dstid = $_SESSION['dstid'];
		$srcid = $_SESSION['srcid'];
		$addons_installed = $_SESSION['addons_installed'];

		// Merge Topics
		if ($step == 1)
		{
			atb_merge_topics($dstid, $srcid);
		}

		// Merge Posts
		if ($step == 2)
		{
			atb_merge_posts($dstid, $srcid);
		}

		// Merge Attachments
		if ($step == 3)
		{
			atb_merge_attachments($dstid, $srcid);
		}

		// Merge Private Messages
		if ($step == 4)
		{
			atb_merge_pm($dstid, $srcid, $_SESSION['deluser']);
		}

		// Some misc things, like Calendar Events, Polls, other 'Lists'
		if ($step == 5)
		{
			atb_merge_others($dstid, $srcid);
		}

		// Drafts
		if ($step == 6)
		{
			atb_merge_drafts($dstid, $srcid);
		}

		// Likes
		if ($step == 7)
		{
			atb_merge_likes($dstid, $srcid);
		}

		// Mentions
		if ($step == 8)
		{
			atb_merge_mentions($dstid, $srcid);
		}

		// Custom Fields
		if ($step == 9)
		{
			atb_merge_custom_fields($dstid, $srcid);
		}

		// Done with standard changes, now on to the addons
		if ($step > 9 && !empty($addons_installed))
		{
			$name = array_pop($addons_installed);
			$_SESSION['addons_installed'] = $addons_installed;
			$sa = 'toolbox_merge_' . $name;
			$this->$sa();
		}

		// Continue?
		if ($step <= $steps)
		{
			// What sub step did we just complete?
			$context['substep_continue_percent'] = 100;
			$context['substep_title'] = isset($txt['toolbox_merge_' . $step])
				? $txt['toolbox_merge_' . $step]
				: (isset($txt['toolbox_merge_' . $name])
					? $txt['toolbox_merge_' . $name] : '');
			$context['substep_enabled'] = !empty($context['substep_title']);

			// Current progress
			$step++;
			$context['continue_get_data'] = '?action=admin;area=toolbox;sa=merge;step=' . $step . ';' . $context['session_var'] . '=' . $context['session_id'];
			$context['continue_percent'] = round(100 * ($step / ($steps + 1)));

			// Really would like to keep running
			if (function_exists('apache_reset_timeout'))
			{
				apache_reset_timeout();
			}

			return;
		}

		// All done, now we munge the user data together in a Frankenstein sort of way :P
		if (!empty($_SESSION['adjustuser']))
		{
			$this->_munge_member_data($dstid, $srcid);
		}

		// Say bu-bye to the old id?
		if (!empty($_SESSION['deluser']))
		{
			require_once(SUBSDIR . '/Members.subs.php');
			deleteMembers($srcid);
		}

		// Recount cause we just changed things up
		if (!empty($_SESSION['deluser']))
		{
			$this->_RecountMemberPosts($dstid);
		}
		else
		{
			$this->_RecountMemberPosts(array($dstid, $srcid));
		}

		// Clean up and move on
		unset($_SESSION['dstid'], $_SESSION['srcid'], $_SESSION['addons_installed'], $_SESSION['steps'], $_SESSION['deluser'], $_SESSION['adjustuser']);
		redirectexit('action=admin;area=toolbox;done=merge');
	}

	/**
	 * Sees if addon tables are installed
	 * Used during the merge user operation
	 */
	private function _toolbox_check_mods()
	{
		global $db_prefix;

		$db = database();

		$addons_installed = array();

		// Get all the tables related to this elkarte install
		$real_prefix = preg_match('~^(`?)(.+?)\\1\\.(.*?)$~', $db_prefix, $match) === 1
			? $match[3]
			: $db_prefix;
		$elk_tables = $db->db_list_tables(false, $real_prefix . '%');

		// Well that was easy, now do the mod tables exist?
		$checkfor_addons = array(
			'aeva' => array($real_prefix . 'aeva_media', $real_prefix . 'aeva_log_media', $real_prefix . 'aeva_albums'),
			'bookmarks' => array($real_prefix . 'bookmarks'),
		);

		// Do all the needed tables for the addon exist in the master list?
		foreach ($checkfor_addons as $addon_name => $addon_dna)
		{
			if (count(array_intersect($elk_tables, $addon_dna)) == count($addon_dna))
			{
				$addons_installed[] = $addon_name;
			}
		}

		return $addons_installed;
	}

	/**
	 * Adjust / Update / Combine member settings as best we can
	 *
	 * @param int $dstid
	 * @param int $srcid
	 */
	private function _munge_member_data($dstid, $srcid)
	{
		global $user_profile;

		// Combine some info, use src id info **IF** dst id info is not set for other, max values for other
		$new_data = array();
		loadMemberData(array($srcid, $dstid), false, 'profile');

		// Combine where it makes sense, and we have data
		if (!empty($user_profile[$srcid]['buddy_list']))
		{
			if (!empty($user_profile[$dstid]['buddy_list']))
			{
				$new_data['buddy_list'] = implode(',', array_unique(array_merge(explode($user_profile[$dstid]['buddy_list'], ','), explode($user_profile[$srcid]['buddy_list'], ','))));
			}
			else
			{
				$new_data['buddy_list'] = $user_profile[$srcid]['buddy_list'];
			}
		}

		if (!empty($user_profile[$srcid]['pm_ignore_list']))
		{
			if (!empty($user_profile[$dstid]['pm_ignore_list']))
			{
				$new_data['pm_ignore_list'] = implode(',', array_unique(array_merge(explode($user_profile[$dstid]['pm_ignore_list'], ','), explode($user_profile[$srcid]['pm_ignore_list'], ','))));
			}
			else
			{
				$new_data['pm_ignore_list'] = $user_profile[$srcid]['pm_ignore_list'];
			}
		}

		if (!empty($user_profile[$srcid]['ignore_boards']))
		{
			if (!empty($user_profile[$dstid]['ignore_boards']))
			{
				$new_data['ignore_boards'] = implode(',', array_unique(array_merge(explode($user_profile[$dstid]['ignore_boards'], ','), explode($user_profile[$srcid]['ignore_boards'], ','))));
			}
			else
			{
				$new_data['ignore_boards'] = $user_profile[$srcid]['ignore_boards'];
			}
		}

		// Combine values together in other cases
		$new_data['karma_bad'] = $user_profile[$dstid]['karma_bad'] + $user_profile[$srcid]['karma_bad'];
		$new_data['karma_good'] = $user_profile[$dstid]['karma_good'] + $user_profile[$srcid]['karma_good'];
		$new_data['total_time_logged_in'] = $user_profile[$dstid]['total_time_logged_in'] + $user_profile[$srcid]['total_time_logged_in'];
		$new_data['mentions'] = $user_profile[$dstid]['mentions'] + $user_profile[$srcid]['mentions'];
		$new_data['unread_messages'] = $user_profile[$dstid]['unread_messages'] + $user_profile[$srcid]['unread_messages'];
		$new_data['new_pm'] = $user_profile[$dstid]['new_pm'] + $user_profile[$srcid]['new_pm'];

		// Or just the use old (src) data if new (dst) data does not exist,
		$new_data['date_registered'] = min($user_profile[$dstid]['date_registered'], $user_profile[$srcid]['date_registered']);
		$new_data['personal_text'] = empty($user_profile[$dstid]['personal_text'])
			? $user_profile[$srcid]['personal_text']
			: $user_profile[$dstid]['personal_text'];
		$new_data['gender'] = empty($user_profile[$dstid]['gender'])
			? $user_profile[$srcid]['gender']
			: $user_profile[$dstid]['gender'];
		$new_data['birthdate'] = ($user_profile[$dstid]['birthdate'] == '0001-01-01')
			? $user_profile[$srcid]['birthdate']
			: $user_profile[$dstid]['birthdate'];
		$new_data['website_title'] = empty($user_profile[$dstid]['website_title'])
			? $user_profile[$srcid]['website_title']
			: $user_profile[$dstid]['website_title'];
		$new_data['website_url'] = empty($user_profile[$dstid]['website_url'])
			? $user_profile[$srcid]['website_url']
			: $user_profile[$dstid]['website_url'];
		$new_data['location'] = empty($user_profile[$dstid]['location'])
			? $user_profile[$srcid]['location']
			: $user_profile[$dstid]['location'];
		$new_data['usertitle'] = empty($user_profile[$dstid]['usertitle'])
			? $user_profile[$srcid]['usertitle']
			: $user_profile[$dstid]['usertitle'];
		$new_data['hide_email'] = empty($user_profile[$dstid]['hide_email'])
			? $user_profile[$srcid]['hide_email']
			: $user_profile[$dstid]['hide_email'];
		$new_data['show_online'] = empty($user_profile[$dstid]['show_online'])
			? $user_profile[$srcid]['show_online']
			: $user_profile[$dstid]['show_online'];
		$new_data['avatar'] = empty($user_profile[$dstid]['avatar'])
			? $user_profile[$srcid]['avatar']
			: $user_profile[$dstid]['avatar'];
		$new_data['signature'] = empty($user_profile[$dstid]['signature'])
			? $user_profile[$srcid]['signature']
			: $user_profile[$dstid]['signature'];
		$new_data['pm_email_notify'] = empty($user_profile[$dstid]['pm_email_notify'])
			? $user_profile[$srcid]['pm_email_notify']
			: $user_profile[$dstid]['pm_email_notify'];
		$new_data['notify_announcements'] = empty($user_profile[$dstid]['notify_announcements'])
			? $user_profile[$srcid]['notify_announcements']
			: $user_profile[$dstid]['notify_announcements'];
		$new_data['notify_regularity'] = empty($user_profile[$dstid]['notify_regularity'])
			? $user_profile[$srcid]['notify_regularity']
			: $user_profile[$dstid]['notify_regularity'];
		$new_data['notify_send_body'] = empty($user_profile[$dstid]['notify_send_body'])
			? $user_profile[$srcid]['notify_send_body']
			: $user_profile[$dstid]['notify_send_body'];
		$new_data['notify_types'] = empty($user_profile[$dstid]['notify_types'])
			? $user_profile[$srcid]['notify_types']
			: $user_profile[$dstid]['notify_types'];

		// Some addon items as well
		if (isset($user_profile[$srcid]['latitude']) && !isset($user_profile[$dstid]['latitude']))
		{
			$new_data['latitude'] = (float) $user_profile[$srcid]['latitude'];
		}
		if (isset($user_profile[$srcid]['longitude']) && !isset($user_profile[$dstid]['longitude']))
		{
			$new_data['latitude'] = (float) $user_profile[$srcid]['longitude'];
		}

		// Update the new ID with the combined / transferred user data
		updateMemberData($dstid, $new_data);
	}

	/**
	 * Takes an array of users and recounts just their post totals
	 *
	 * @param int[]|int $members
	 */
	private function _RecountMemberPosts($members)
	{
		global $modSettings;

		$db = database();

		// keep the bad monkeys away
		checkSession('request');

		// Can't do it if there's no info
		if (empty($members))
		{
			return;
		}

		// It must be an array
		if (!is_array($members))
		{
			$members = array($members);
		}

		// Lets get their post counts
		$request = $db->query('', '
			SELECT
				m.id_member, COUNT(m.id_member) AS posts
			FROM {db_prefix}messages AS m
				LEFT JOIN {db_prefix}boards AS b ON b.id_board = m.id_board
			WHERE m.id_member IN ({array_int:members})
				AND b.count_posts = {int:zero}' . (!empty($modSettings['recycle_enable'])
				? ('
				AND b.id_board != ' . $modSettings['recycle_board'])
				: ''),
			array(
				'zero' => 0,
				'members' => $members,
			)
		);
		// Update the count for these members
		while ($row = $db->fetch_assoc($request))
		{
			if (isset($row['id_member']))
			{
				$db->query('', '
					UPDATE {db_prefix}members
					SET posts = {int:posts}
					WHERE id_member = {int:id_member}',
					array(
						'id_member' => $row['id_member'],
						'posts' => $row['posts'],
					)
				);
			}
		}
		$db->free_result($request);
	}

	/**
	 * toolbox_merge_bookmarks()
	 *
	 * Does the merging of bookmarks
	 */
	public function toolbox_merge_bookmarks()
	{
		$db = database();

		$dstid = $_SESSION['dstid'];
		$srcid = $_SESSION['srcid'];

		// Then link ratings
		$db->query('', '
			UPDATE {db_prefix}bookmarks
			SET id_member = {int:dstid}
			WHERE id_member = {int:srcid}',
			array(
				'dstid' => $dstid,
				'srcid' => $srcid
			)
		);
	}

	/**
	 * toolbox_merge_aeva()
	 *
	 * Oofta, merges all the Aeva album information, I hope I have them all !
	 */
	public function toolbox_merge_aeva()
	{
		$db = database();

		$dstid = $_SESSION['dstid'];
		$srcid = $_SESSION['srcid'];

		// The new owners name is needed in some places, just do it once now
		$request = $db->query('', '
			SELECT
			 	member_name
			FROM {db_prefix}members
			WHERE id_member = {int:dstid}
			LIMIT 1',
			array(
				'dstid' => $dstid,
			)
		);
		list($dstname) = $db->fetch_row($request);
		$db->free_result($request);

		// First album ownership
		$db->query('', '
			UPDATE {db_prefix}aeva_albums
			SET album_of = {int:dstid}
			WHERE album_of = {int:srcid}',
			array(
				'dstid' => $dstid,
				'srcid' => $srcid
			)
		);

		// And now the actual album items and who edited them as well
		$db->query('', '
			UPDATE {db_prefix}aeva_media
			SET id_member = {int:dstid}, member_name = {string:dstname}
			WHERE id_member = {int:srcid}',
			array(
				'dstid' => $dstid,
				'srcid' => $srcid,
				'dstname' => $dstname
			)
		);

		$db->query('', '
			UPDATE {db_prefix}aeva_media
			SET last_edited_by = {int:dstid}, last_edited_name = {string:dstname}
			WHERE last_edited_by = {int:srcid}',
			array(
				'dstid' => $dstid,
				'srcid' => $srcid,
				'dstname' => $dstname
			)
		);

		$db->query('', '
			UPDATE {db_prefix}aeva_log_media
			SET id_member = {int:dstid}
			WHERE id_member = {int:srcid}',
			array(
				'dstid' => $dstid,
				'srcid' => $srcid,
			)
		);
	}
}