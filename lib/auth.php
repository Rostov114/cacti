<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2017 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/


/* clear_auth_cookie - clears a users security token
 * @return - NULL */
function clear_auth_cookie() {
	global $config;

	if (isset($_COOKIE['cacti_remembers']) && read_config_option('auth_cache_enabled') == 'on') {
		$parts = explode(',', $_COOKIE['cacti_remembers']);
		$user  = $parts[0];

		if ($user != '') {
			$user_id = db_fetch_cell_prepared('SELECT id FROM user_auth WHERE username = ?', array($user));

			if (!empty($user_id)) {
				if (isset($parts[1])) {
					$nssecret  = $parts[1];
					$secret = hash('sha512', $nssecret, false);
					setcookie('cacti_remembers', '', time() - 3600, $config['url_path']);
					db_execute_prepared('DELETE FROM user_auth_cache WHERE user_id = ? AND token = ?', array($user_id, $secret));
				}
			}
		}
	}
}

/* set_auth_cookie - sets a users security token
 * @arg - (string) $user - The user_auth row for the user
 * @return - (boolean) True if token set worked, otherwise false */
function set_auth_cookie($user) {
	global $config;

	clear_auth_cookie();

	$nssecret = md5($_SERVER['REQUEST_TIME'] .  mt_rand(10000,10000000)) . md5($_SERVER['REMOTE_ADDR']);

	$secret = hash('sha512', $nssecret, false);

	db_execute_prepared('REPLACE INTO user_auth_cache 
		(user_id, hostname, last_update, token) 
		VALUES 
		(?, ?, NOW(), ?);', array($user['id'], $_SERVER['REMOTE_ADDR'], $secret));

	setcookie('cacti_remembers', $user['username'] . ',' . $nssecret, time()+(86400*30), $config['url_path']);
}

/* check_auth_cookie - clears a users security token
 * @return - (int) The user of the session cookie, otherwise false */
function check_auth_cookie() {
	if (isset($_COOKIE['cacti_remembers']) && read_config_option('auth_cache_enabled') == 'on') {
		$parts = explode(',', $_COOKIE['cacti_remembers']);
		$user  = $parts[0];

		if ($user != '') {
			$user_info = db_fetch_row_prepared('SELECT id, username 
				FROM user_auth 
				WHERE username = ?', array($user));

			if (!empty($user_info)) {
				if (isset($parts[1])) {
					$nssecret = $parts[1];

					$secret = hash('sha512', $nssecret, false);

					$found  = db_fetch_cell_prepared('SELECT user_id 
						FROM user_auth_cache 
						WHERE user_id = ? AND token = ?', array($user_info['id'], $secret));

					if (empty($found)) {
						return false;
					}else{
						set_auth_cookie($user_info);

						cacti_log("LOGIN: User '" . $user_info['username'] . "' Authenticated via Authentication Cookie", false, 'AUTH');

						db_execute_prepared('INSERT IGNORE INTO user_log 
							(username, user_id, result, ip, time) 
							VALUES 
							(?, ?, 2, ?, NOW())', array($user, $user_info['id'], $_SERVER['REMOTE_ADDR']));
						return $user_info['id'];
					}
				}
			}
		}
	}

	return false;
}

/* user_copy - copies user account
   @arg $template_user - username of the user account that should be used as the template
   @arg $new_user - new username of the account to be created/overwritten
   @arg $new_realm - new realm of the account to be created, overwrite not affected, but is used for lookup
   @arg $overwrite - Allow overwrite of existing user, preserves username, fullname, password and realm
   @arg $data_override - Array of user_auth field and values to override on the new user
   @return - True on copy, False on no copy */
function user_copy($template_user, $new_user, $template_realm = 0, $new_realm = 0, $overwrite = false, $data_override = array()) {

	/* ================= input validation ================= */
	input_validate_input_number($template_realm);
	input_validate_input_number($new_realm);
	/* ==================================================== */

	/* Check get template users array */
	$user_auth = db_fetch_row_prepared('SELECT * FROM user_auth WHERE username = ? AND realm = ?', array($template_user, $template_realm));
	if (! isset($user_auth)) {
		return false;
	}
	$template_id = $user_auth['id'];

	/* Create update/insert for new/existing user */
	$user_exist = db_fetch_row_prepared('SELECT * FROM user_auth WHERE username = ? AND realm = ?', array($new_user, $new_realm));
	if (sizeof($user_exist)) {
		if ($overwrite) {
			/* Overwrite existing user */
			$user_auth['id']        = $user_exist['id'];
			$user_auth['username']  = $user_exist['username'];
			$user_auth['password']  = $user_exist['password'];
			$user_auth['realm']     = $user_exist['realm'];
			$user_auth['full_name'] = $user_exist['full_name'];
			$user_auth['must_change_password'] = $user_exist['must_change_password'];
			$user_auth['enabled']   = $user_exist['enabled'];
		}else{
			/* User already exists, duplicate users are bad */
			raise_message(19);
			return false;
		}
	} else {
		/* new user */
		$user_auth['id'] = 0;
		$user_auth['username'] = $new_user;
		$user_auth['password'] = '!';
		$user_auth['realm'] = $new_realm;
	}

	/* Update data_override fields */
	if (is_array($data_override)) {
		foreach ($data_override as $field => $value) {
			if ((isset($user_auth[$field])) && ($field != 'id') && ($field != 'username')) {
				$user_auth[$field] = $value;
			}
		}
	}

	/* Save the user */
	$new_id = sql_save($user_auth, 'user_auth');

	/* Create/Update permissions and settings */
	if ((isset($user_exist)) && ($overwrite )) {
		db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ?', array($user_exist['id']));
		db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ?', array($user_exist['id']));
		db_execute_prepared('DELETE FROM settings_user WHERE user_id = ?', array($user_exist['id']));
		db_execute_prepared('DELETE FROM settings_tree WHERE user_id = ?', array($user_exist['id']));
	}

	$user_auth_perms = db_fetch_assoc_prepared('SELECT * FROM user_auth_perms WHERE user_id = ?', array($template_id));
	if (isset($user_auth_perms)) {
		foreach ($user_auth_perms as $row) {
			$row['user_id'] = $new_id;
			sql_save($row, 'user_auth_perms', array('user_id', 'item_id', 'type'), false);
		}
	}

	$user_auth_realm = db_fetch_assoc_prepared('SELECT * FROM user_auth_realm WHERE user_id = ?', array($template_id));
	if (isset($user_auth_realm)) {
		foreach ($user_auth_realm as $row) {
			$row['user_id'] = $new_id;
			sql_save($row, 'user_auth_realm', array('realm_id', 'user_id'), false);
		}
	}

	$settings_user = db_fetch_assoc_prepared('SELECT * FROM settings_user WHERE user_id = ?', array($template_id));
	if (isset($settings_user)) {
		foreach ($settings_user as $row) {
			$row['user_id'] = $new_id;
			sql_save($row, 'settings_user', array('user_id', 'name'), false);
		}
	}

	$settings_tree = db_fetch_assoc_prepared('SELECT * FROM settings_tree WHERE user_id = ?', array($template_id));
	if (isset($settings_tree)) {
		foreach ($settings_tree as $row) {
			$row['user_id'] = $new_id;
			sql_save($row, 'settings_tree', array('user_id', 'graph_tree_item_id'), false);
		}
	}

	/* apply group permissions for the user */
	$groups = db_fetch_assoc_prepared('SELECT group_id FROM user_auth_group_members WHERE user_id = ?', array($template_id));
	if (sizeof($groups)) {
		foreach($groups as $g) {
			$sql[] = '(' . $new_id . ', ' . $g['group_id'] . ')';
		}

		db_execute('INSERT IGNORE INTO user_auth_group_members (user_id, group_id) VALUES ' . implode(',', $sql));
	}

	api_plugin_hook_function('copy_user', array('template_id' => $template_id, 'new_id' => $new_id));

	return true;
}


/* user_remove - remove a user account
   @arg $user_id - Id os the user account to remove */
function user_remove($user_id) {
	/* ================= input validation ================= */
	input_validate_input_number($user_id);
	/* ==================================================== */

	/* check for guest or template user */
	$username = db_fetch_cell_prepared('SELECT username FROM user_auth WHERE id = ?', array($user_id));
	if ($username != get_nfilter_request_var('username')) {
		if ($username == read_config_option('user_template')) {
			raise_message(21);
			return;
		}
		if ($username == read_config_option('guest_user')) {
			raise_message(21);
			return;
		}
	}

	db_execute_prepared('DELETE FROM user_auth WHERE id = ?', array($user_id));
	db_execute_prepared('DELETE FROM user_auth_realm WHERE user_id = ?', array($user_id));
	db_execute_prepared('DELETE FROM user_auth_perms WHERE user_id = ?', array($user_id));
	db_execute_prepared('DELETE FROM user_auth_group_members WHERE user_id = ?', array($user_id));
	db_execute_prepared('DELETE FROM settings_user WHERE user_id = ?', array($user_id));
	db_execute_prepared('DELETE FROM settings_tree WHERE user_id = ?', array($user_id));

	api_plugin_hook_function('user_remove', $user_id);
}

/* user_disable - disable a user account
   @arg $user_id - Id of the user account to disable */
function user_disable($user_id) {
	/* ================= input validation ================= */
	input_validate_input_number($user_id);
	/* ==================================================== */

	db_execute_prepared('UPDATE user_auth SET enabled = \'\' WHERE id = ?', array($user_id));
}

/* user_enable - enable a user account
   @arg $user_id - Id of the user account to enable */
function user_enable($user_id) {
	/* ================= input validation ================= */
	input_validate_input_number($user_id);
	/* ==================================================== */

	db_execute_prepared('UPDATE user_auth SET enabled = \'on\' WHERE id = ?', array($user_id));
}

/* get_auth_realms - return a list of system user authentication realms */
function get_auth_realms($login = false) {
	static $realms = array();

	if (sizeof($realms)) {
		return $realms;
	}

	$realms = array(
		0 => __('Local'),
		1 => __('LDAP'),
		2 => __('Web Basic')
	);

	if (db_table_exists('user_domains')) {
		$drealms = db_fetch_assoc('SELECT * FROM user_domains WHERE enabled="on" ORDER BY domain_name');
		$default_realm = db_fetch_cell('SELECT domain_id FROM user_domains WHERE defdomain=1 AND enabled="on"');

		if (sizeof($drealms)) {
			if ($login) {
				$new_realms['local'] = array('name' => __('Local'), 'selected' => false);
				foreach($drealms as $realm) {
					$new_realms[1000+$realm['domain_id']] = array('name' => $realm['domain_name'], 'selected' => false);
				}

				if (!empty($default_realm)) {
					$new_realms[1000+$default_realm]['selected'] = true;
				}else{
					$new_realms['local']['selected'] = true;
				}
			}else{
				$new_realms['0'] = __('Local');
				foreach($drealms as $realm) {
					$new_realms[1000+$realm['domain_id']] = $realm['domain_name'];
				}
			}

			$realms += $new_realms;
		}
	}

	return $realms;
}

/* get_graph_permissions_sql - creates SQL that reprents the current graph, host and graph
     template policies
   @arg $policy_graphs - (int) the current graph policy
   @arg $policy_hosts - (int) the current host policy
   @arg $policy_graph_templates - (int) the current graph template policy
   @returns - an SQL "where" statement */
function get_graph_permissions_sql($policy_graphs, $policy_hosts, $policy_graph_templates) {
	$sql = '';
	$sql_or = '';
	$sql_and = '';
	$sql_policy_or = '';
	$sql_policy_and = '';

	if ($policy_graphs == '1') {
		$sql_policy_and .= "$sql_and(user_auth_perms.type != 1 OR user_auth_perms.type is null)";
		$sql_and = ' AND ';
		$sql_null = 'is null';
	}elseif ($policy_graphs == '2') {
		$sql_policy_or .= "$sql_or(user_auth_perms.type = 1 OR user_auth_perms.type is not null)";
		$sql_or = ' OR ';
		$sql_null = 'is not null';
	}

	if ($policy_hosts == '1') {
		$sql_policy_and .= "$sql_and((user_auth_perms.type != 3) OR (user_auth_perms.type is null))";
		$sql_and = ' AND ';
	}elseif ($policy_hosts == '2') {
		$sql_policy_or .= "$sql_or((user_auth_perms.type = 3) OR (user_auth_perms.type is not null))";
		$sql_or = ' OR ';
	}

	if ($policy_graph_templates == '1') {
		$sql_policy_and .= "$sql_and((user_auth_perms.type != 4) OR (user_auth_perms.type is null))";
		$sql_and = ' AND ';
	}elseif ($policy_graph_templates == '2') {
		$sql_policy_or .= "$sql_or((user_auth_perms.type = 4) OR (user_auth_perms.type is not null))";
		$sql_or = ' OR ';
	}

	$sql_and = '';

	if (!empty($sql_policy_or)) {
		$sql_and = 'AND ';
		$sql .= $sql_policy_or;
	}

	if (!empty($sql_policy_and)) {
		$sql .= "$sql_and$sql_policy_and";
	}

	if (empty($sql)) {
		return '';
	}else{
		return '(' . $sql . ')';
	}
}

/* is_graph_allowed - determines whether the current user is allowed to view a certain graph
   @arg $local_graph_id - (int) the ID of the graph to check permissions for
   @returns - (bool) whether the current user is allowed the view the specified graph or not */
function is_graph_allowed($local_graph_id, $user = 0) {
	$rows  = 0;

	$graph = get_allowed_graphs('', '', '', $rows, $user, $local_graph_id);

	if ($rows > 0) {
		return true;
	}else{
		return false;
	}
}

function auth_check_perms(&$objects, $policy) {
	/* policy == allow AND matches = DENY */
	if (sizeof($objects) && $policy == 1) {
		return false;
	/* policy == deny AND matches = ALLOW */
	}elseif (sizeof($objects) && $policy == 2) {
		return true;
	/* policy == allow AND no matches = ALLOW */
	}elseif (!sizeof($objects) && $policy == 1) {
		return true;
	/* policy == deny AND no matches = DENY */
	}elseif (!sizeof($objects) && $policy == 2) {
		return false;
	}
}

/* is_tree_allowed - determines whether the current user is allowed to view a certain graph tree
   @arg $tree_id - (int) the ID of the graph tree to check permissions for
   @returns - (bool) whether the current user is allowed the view the specified graph tree or not */
function is_tree_allowed($tree_id, $user = 0) {
	if ($user == -1) {
		$auth_method = 0;
	}else{
		$auth_method = read_config_option('auth_method');
	}

	if ($auth_method != 0) {
		if ($user == 0) {
			if (isset($_SESSION['sess_user_id'])) {
				$user = $_SESSION['sess_user_id'];
			}else{
				return array();
			}
		}

		$policy = db_fetch_cell_prepared('SELECT policy_trees 
			FROM user_auth 
			WHERE id = ?', 
			array($user));

		$trees  = db_fetch_assoc_prepared('SELECT user_id 
			FROM user_auth_perms 
			WHERE user_id = ? 
			AND type = 2 
			AND item_id = ?', 
			array($user, $tree_id));

		$authorized = auth_check_perms($trees, $policy);

		/* check for group perms */
		if (!$authorized) {
			$groups = db_fetch_assoc_prepared('SELECT uag.* 
				FROM user_auth_group AS uag
				INNER JOIN user_auth_group_members AS uagm
				ON uag.id = uagm.group_id
				WHERE uag.enabled = \'on\' AND uagm.user_id = ?', array($user));

			if (sizeof($groups)) {
				foreach($groups as $g) {
					$policy = $g['policy_trees'];
					$trees  = db_fetch_assoc_prepared('SELECT user_id 
						FROM user_auth_perms 
						WHERE user_id = ? 
						AND type = 2 
						AND item_id = ?', 
						array($user, $tree_id));

					$authorized = auth_check_perms($trees, $policy);
					if ($authorized) {
						return true;
					}
				}

				return false;
			}else{
				return false;
			}
		}else{
			return true;
		}
	}else{
		return true;
	}
}

/* is_device_allowed - determines whether the current user is allowed to view a certain device
   @arg $host_id - (int) the ID of the device to check permissions for
   @returns - (bool) whether the current user is allowed the view the specified device or not */
function is_device_allowed($host_id, $user = 0) {
	$total_rows = 0;
	$host = get_allowed_devices('', '', '', $total_rows, $user, $host_id);

	if ($total_rows > 0) {
		return true;
	}else{
		return false;
	}
}

/* is_graph_template_allowed - determines whether the current user is allowed to view a certain graph template
   @arg $graph_template_id - (int) the ID of the graph template to check permissions for
   @returns - (bool) whether the current user is allowed the view the specified graph template or not */
function is_graph_template_allowed($graph_template_id, $user = 0) {
	$total_rows = 0;
	$template = get_allowed_graph_templates('', '', '', $total_rows, $user, $graph_template_id);

	if ($total_rows > 0) {
		return true;
	}else{
		return false;
	}
}

/* is_view_allowed - Returns a true or false as to wether or not a specific view type is allowed
 *                   View options include 'show_tree', 'show_list', 'show_preview', 'graph_settings'
 */
function is_view_allowed($view = 'show_tree') {
	if (read_config_option('auth_method') != 0) {
		$values = array_rekey(db_fetch_assoc_prepared("SELECT DISTINCT $view
			FROM user_auth_group AS uag
			INNER JOIN user_auth_group_members AS uagm
			ON uag.id = uagm.user_id
			WHERE uag.enabled = 'on' 
			AND uagm.user_id = ?", array($_SESSION['sess_user_id'])), $view, $view);

		if (isset($values[3])) {
			return false;
		}elseif (isset($values['on'])) {
			return true;
		}elseif (isset($values[2])) {
			return true;
		}else{
			$value = db_fetch_cell_prepared("SELECT $view 
				FROM user_auth 
				WHERE id = ?", 
				array($_SESSION['sess_user_id']));

			if ($value == 'on') {
				return true;
			}else{
				return false;
			}
		}
	}else{
		return true;
	}
}

function is_tree_branch_empty($tree_id, $parent = 0) {
	$graphs = array_rekey(
		db_fetch_assoc_prepared('SELECT local_graph_id 
			FROM graph_tree_items 
			WHERE graph_tree_id = ? 
			AND parent = ?', 
			array($tree_id, $parent)),
		'local_graph_id', 'local_graph_id');

	$hosts  = array_rekey(
		db_fetch_assoc_prepared('SELECT host_id 
			FROM graph_tree_items 
			WHERE graph_tree_id = ? 
			AND parent = ?', 
			array($tree_id, $parent)),
		'host_id', 'host_id');

	$branches = db_fetch_assoc_prepared('SELECT *
		FROM graph_tree_items 
		WHERE graph_tree_id = ? 
		AND parent = ? 
		AND local_graph_id = 0
		AND host_id = 0',
		array($tree_id, $parent));

	$allowed_graphs  = 0;
	$allowed_devices = 0;
	if (sizeof($graphs)) {
		$allowed_graphs  = sizeof(get_allowed_graphs('gl.id IN(' . implode(',', $graphs) . ')'));
	}

	if (sizeof($hosts)) {
		$allowed_devices = sizeof(get_allowed_devices('h.id IN(' . implode(',', $hosts) . ')'));
	}

	if (($allowed_graphs + $allowed_devices) > 0) {
		return false;
	}elseif (sizeof($branches)) { 
		foreach($branches as $b) {
			if (!is_tree_branch_empty($b['graph_tree_id'], $b['id'])) {
				return false;
			}
		}

		return true;
	}else{
		return true;
	}
}

function is_realm_allowed($realm) {
	global $user_auth_realms, $config;

	/* list all realms that this user has access to */
	if (read_config_option('auth_method') != 0) {
		if (isset($_SESSION['sess_user_id'])) {
			if (!user_perms_valid($_SESSION['sess_user_id'])) {
				kill_session_var('sess_user_realms');
				kill_session_var('sess_graph_config_array');
				kill_session_var('sess_config_array');
			}

			if (isset($_SESSION['sess_user_realms'][$realm])) {
				return true;
			}elseif (read_config_option('auth_method') != 0) {
				if (version_compare($config['cacti_db_version'], '1.0.0') >= 0) {
					$user_realm = db_fetch_cell_prepared('SELECT realm_id 
						FROM user_auth_realm 
						WHERE user_id = ?
						AND realm_id = ?
						UNION
						SELECT realm_id
						FROM user_auth_group_realm AS uagr
						INNER JOIN user_auth_group AS uag
						ON uag.id = uagr.group_id
						INNER JOIN user_auth_group_members AS uagm
						ON uag.id = uagm.group_id
						WHERE uag.enabled = \'on\' AND uagr.realm_id = ?
						AND uagm.user_id = ?', array($_SESSION['sess_user_id'], $realm, $realm, $_SESSION['sess_user_id']));
				}else{
					$user_realm = db_fetch_cell_prepared('SELECT realm_id 
						FROM user_auth_realm 
						WHERE user_id = ?
						AND realm_id = ?', array($_SESSION['sess_user_id'], $realm));
				}

				if (!empty($user_realm)) {
					$_SESSION['sess_user_realms'][$realm] = $realm;
					return true;
				}else{
					return false;
				}
			}else{
				$_SESSION['sess_user_realms'][$realm] = $realm;
			}
		}else{
			return false;
		}
	}else{
		return true;
	}
}

function get_allowed_tree_level($tree_id, $parent_id, $user = 0) {
	$items = db_fetch_assoc_prepared('SELECT gti.id, gti.title, gti.host_id, 
		gti.local_graph_id, gti.host_grouping_type, h.description AS hostname
		FROM graph_tree_items AS gti
		INNER JOIN graph_tree AS gt
		ON gt.id = gti.graph_tree_id
		LEFT JOIN host AS h
		ON h.id = gti.host_id
		WHERE graph_tree_id = ?
		AND parent = ?
		ORDER BY position ASC', array($tree_id, $parent_id));

	$i     = 0;
	if (sizeof($items)) {
	foreach($items as $item) {
		if ($item['host_id'] > 0) {
			if (!is_device_allowed($item['host_id'], $user)) {
				unset($items[$i]);
			}
		}elseif($item['local_graph_id'] > 0) {
			if (!is_graph_allowed($item['local_graph_id'], $user)) {
				unset($items[$i]);
			}
		}

		$i++;
	}
	}

	return $items;
}

function get_allowed_tree_content($tree_id, $parent = 0, $sql_where = '', $order_by = '', $limit = '', &$total_rows = 0, $user = 0) {
	if ($limit != '') {
		$limit = "LIMIT $limit";
	}

	if ($order_by != '') {
		$order_by = "ORDER BY $order_by";
	}

	if ($sql_where != '') {
		$sql_where = "WHERE gti.local_graph_id=0 AND gti.parent=$parent AND gti.graph_tree_id=$tree_id AND (" . $sql_where . ')';
	}else{
		$sql_where = "WHERE gti.local_graph_id=0 AND gti.parent=$parent AND gti.graph_tree_id=$tree_id";
	}

	if ($tree_id > 0) {
		$heirarchy = db_fetch_assoc("SELECT gti.graph_tree_id AS tree_id, gti.id, gti.title, gti.host_id, 
			gti.local_graph_id, gti.host_grouping_type, h.description AS hostname
			FROM graph_tree_items AS gti
			INNER JOIN graph_tree AS gt
			ON gt.id = gti.graph_tree_id
			LEFT JOIN host AS h
			ON h.id = gti.host_id
			$sql_where
			ORDER BY gti.position");
	}else{
		$heirarchy = db_fetch_assoc("SELECT gt.id AS tree_id, '0' AS id, gt.name AS title, '0' AS host_id, 
			'0' AS local_graph_id, '1' AS host_grouping_type, '' AS hostname
			FROM graph_tree AS gt
			WHERE enabled='on'
			ORDER BY gt.sequence");
	}

	if (read_config_option('auth_method') != 0) {
		$new_heirarchy = array();
		if (sizeof($heirarchy)) {
			foreach($heirarchy as $h) {
				if ($h['host_id'] > 0) {
					if (is_device_allowed($h['host_id'])) {
						$new_heirarchy[] = $h;
					}
				}elseif ($h['id'] == 0) {
					if (!is_tree_branch_empty($h['tree_id'], $h['id'])) {
						if (is_tree_allowed($h['tree_id'])) {
							$new_heirarchy[] = $h;
						}
					}
				}elseif (!is_tree_branch_empty($h['tree_id'], $h['id'])) {
					$new_heirarchy[] = $h;
				}
			}
		}

		return $new_heirarchy;
	}else{
		return $heirarchy;
	}
}

function get_allowed_tree_header_graphs($tree_id, $leaf_id = 0, $sql_where = '', $order_by = 'gti.position', $limit = '', &$total_rows = 0, $user = 0) {
	if ($limit != '') {
		$limit = "LIMIT $limit";
	}

	if ($order_by != '') {
		$order_by = "ORDER BY $order_by";
	}

	if (strlen($sql_where)) {
		$sql_where = " AND ($sql_where)";
	}

	$sql_where = "WHERE (gti.graph_tree_id=$tree_id AND gti.parent=$leaf_id)" . $sql_where;

	$i          = 0;
	$sql_having = '';
	$sql_select = '';
	$sql_join   = '';

	if ($user == -1) {
		$auth_method = 0;
	}else{
		$auth_method = read_config_option('auth_method');
	}

	if ($auth_method != 0) {
		if ($user == 0) {
			if (isset($_SESSION['sess_user_id'])) {
				$user = $_SESSION['sess_user_id'];
			}else{
				return array();
			}
		}

		if (read_config_option('graph_auth_method') == 1) {
			$sql_operator = 'OR';
		}else{
			$sql_operator = 'AND';
		}

		/* get policies for all groups and user */
		$policies   = db_fetch_assoc_prepared("SELECT uag.id, 'group' AS type, 
			policy_graphs, policy_hosts, policy_graph_templates 
			FROM user_auth_group AS uag
			INNER JOIN user_auth_group_members AS uagm
			ON uag.id = uagm.group_id
			WHERE uag.enabled = 'on' AND uagm.user_id = ?", 
			array($user));

		$policies[] = db_fetch_row_prepared("SELECT id, 'user' AS type, 
			policy_graphs, policy_hosts, policy_graph_templates 
			FROM user_auth WHERE id = ?", 
			array($user));
		
		foreach($policies as $policy) {
			if ($policy['policy_graphs'] == 1) {
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "(user$i IS NULL";
			}else{
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "(user$i IS NOT NULL";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.id=uap$i.item_id AND uap$i.type=1 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;

			if ($policy['policy_hosts'] == 1) {
				$sql_having .= " OR (user$i IS NULL";
			}else{
				$sql_having .= " OR (user$i IS NOT NULL";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.host_id=uap$i.item_id AND uap$i.type=3 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;

			if ($policy['policy_graph_templates'] == 1) {
				$sql_having .= " $sql_operator user$i IS NULL))";
			}else{
				$sql_having .= " $sql_operator user$i IS NOT NULL))";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.graph_template_id=uap$i.item_id AND uap$i.type=4 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;
		}

		$sql_having = "HAVING $sql_having";

		$graphs = db_fetch_assoc("SELECT gti.id, gti.title, gtg.local_graph_id, 
			h.description, gt.name AS template_name, gtg.title_cache, 
			gtg.width, gtg.height, gl.snmp_index, gl.snmp_query_id,
			$sql_select
			FROM graph_templates_graph AS gtg 
			INNER JOIN graph_local AS gl 
			ON gl.id = gtg.local_graph_id 
			INNER JOIN graph_tree_items AS gti
			ON gti.local_graph_id = gl.id
			LEFT JOIN graph_templates AS gt 
			ON gt.id = gl.graph_template_id 
			LEFT JOIN host AS h 
			ON h.id = gl.host_id 
			$sql_join
			$sql_where
			$sql_having
			$order_by
			$limit");

		$total_rows = db_fetch_cell("SELECT COUNT(*)
			FROM (
				SELECT $sql_select
				FROM graph_templates_graph AS gtg 
				INNER JOIN graph_local AS gl 
				ON gl.id = gtg.local_graph_id 
				INNER JOIN graph_tree_items AS gti
				ON gti.local_graph_id = gl.id
				LEFT JOIN graph_templates AS gt 
				ON gt.id = gl.graph_template_id 
				LEFT JOIN host AS h 
				ON h.id = gl.host_id 
				$sql_join
				$sql_where
				$sql_having
			) AS rower");
	}else{
		$graphs = db_fetch_assoc("SELECT 
			gti.id, gti.title, 
			gtg.local_graph_id, 
			h.description, 
			gt.name AS template_name, 
			gtg.title_cache, 
			gtg.width, 
			gtg.height,
			gl.snmp_index,
			gl.snmp_query_id
			FROM graph_templates_graph AS gtg 
			INNER JOIN graph_local AS gl 
			ON gl.id = gtg.local_graph_id 
			INNER JOIN graph_tree_items AS gti
			ON gti.local_graph_id = gl.id
			LEFT JOIN graph_templates AS gt 
			ON gt.id = gl.graph_template_id 
			LEFT JOIN host AS h 
			ON h.id = gl.host_id 
			$sql_where
			$order_by
			$limit");

		$total_rows = db_fetch_cell("SELECT COUNT(*)
			FROM graph_templates_graph AS gtg 
			INNER JOIN graph_local AS gl 
			ON gl.id=gtg.local_graph_id 
			INNER JOIN graph_tree_items AS gti
			ON gti.local_graph_id=gl.id
			LEFT JOIN graph_templates AS gt 
			ON gt.id=gl.graph_template_id 
			LEFT JOIN host AS h 
			ON h.id=gl.host_id 
			$sql_where");
	}

	return $graphs;
}

function get_allowed_graphs($sql_where = '', $order_by = 'gtg.title_cache', $limit = '', &$total_rows = 0, $user = 0, $graph_id = 0) {
	if ($limit != '') {
		$limit = "LIMIT $limit";
	}

	if ($order_by != '') {
		$order_by = "ORDER BY $order_by";
	}

	if ($graph_id > 0) {
		$sql_where .= (strlen($sql_where) ? ' AND ':' ') . " gl.id=$graph_id";
	}

	if (strlen($sql_where)) {
		$sql_where = "WHERE $sql_where";
	}

	$i          = 0;
	$sql_having = '';
	$sql_select = '';
	$sql_join   = '';

	if ($user == -1) {
		$auth_method = 0;
	}else{
		$auth_method = read_config_option('auth_method');
	}

	if ($auth_method != 0) {
		if ($user == 0) {
			if (isset($_SESSION['sess_user_id'])) {
				$user = $_SESSION['sess_user_id'];
			}else{
				return array();
			}
		}

		if (read_config_option('graph_auth_method') == 1) {
			$sql_operator = 'OR';
		}else{
			$sql_operator = 'AND';
		}

		/* get policies for all groups and user */
		$policies   = db_fetch_assoc_prepared("SELECT uag.id, 
			'group' AS type, policy_graphs, policy_hosts, policy_graph_templates 
			FROM user_auth_group AS uag
			INNER JOIN user_auth_group_members AS uagm
			ON uag.id = uagm.group_id
			WHERE uag.enabled = 'on' AND uagm.user_id = ?", array($user));

		$policies[] = db_fetch_row_prepared("SELECT id, 'user' AS type, policy_graphs, policy_hosts, policy_graph_templates FROM user_auth WHERE id = ?", array($user));
		
		foreach($policies as $policy) {
			if ($policy['policy_graphs'] == 1) {
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "(user$i IS NULL";
			}else{
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "(user$i IS NOT NULL";
			}

			$sql_join   .= "LEFT JOIN user_auth_" . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.id=uap$i.item_id AND uap$i.type=1 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;

			if ($policy['policy_hosts'] == 1) {
				$sql_having .= " OR (user$i IS NULL";
			}else{
				$sql_having .= " OR (user$i IS NOT NULL";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.host_id=uap$i.item_id AND uap$i.type=3 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;

			if ($policy['policy_graph_templates'] == 1) {
				$sql_having .= " $sql_operator user$i IS NULL))";
			}else{
				$sql_having .= " $sql_operator user$i IS NOT NULL))";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.graph_template_id=uap$i.item_id AND uap$i.type=4 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;
		}

		$sql_having = "HAVING $sql_having";

		$graphs = db_fetch_assoc("SELECT gtg.local_graph_id, h.description, gt.name AS template_name, 
			gtg.title_cache, gtg.width, gtg.height, gl.snmp_index, gl.snmp_query_id,
			$sql_select
			FROM graph_templates_graph AS gtg 
			INNER JOIN graph_local AS gl 
			ON gl.id=gtg.local_graph_id 
			LEFT JOIN graph_templates AS gt 
			ON gt.id=gl.graph_template_id 
			LEFT JOIN host AS h 
			ON h.id=gl.host_id 
			$sql_join
			$sql_where
			$sql_having
			$order_by
			$limit");

		$total_rows = db_fetch_cell("SELECT COUNT(*)
			FROM (
				SELECT $sql_select
				FROM graph_templates_graph AS gtg 
				INNER JOIN graph_local AS gl 
				ON gl.id=gtg.local_graph_id 
				LEFT JOIN graph_templates AS gt 
				ON gt.id=gl.graph_template_id 
				LEFT JOIN host AS h 
				ON h.id=gl.host_id 
				$sql_join
				$sql_where
				$sql_having
			) AS rower");
	}else{
		$graphs = db_fetch_assoc("SELECT 
			gtg.local_graph_id, 
			h.description, 
			gt.name AS template_name, 
			gtg.title_cache, 
			gtg.width, 
			gtg.height,
			gl.snmp_index,
			gl.snmp_query_id
			FROM graph_templates_graph AS gtg 
			INNER JOIN graph_local AS gl 
			ON gl.id=gtg.local_graph_id 
			LEFT JOIN graph_templates AS gt 
			ON gt.id=gl.graph_template_id 
			LEFT JOIN host AS h 
			ON h.id=gl.host_id 
			$sql_where
			$order_by
			$limit");

		$total_rows = db_fetch_cell("SELECT COUNT(*)
			FROM graph_templates_graph AS gtg 
			INNER JOIN graph_local AS gl 
			ON gl.id=gtg.local_graph_id 
			LEFT JOIN graph_templates AS gt 
			ON gt.id=gl.graph_template_id 
			LEFT JOIN host AS h 
			ON h.id=gl.host_id 
			$sql_where");
	}

	return $graphs;
}

function get_allowed_trees($edit = false, $return_sql = false, $sql_where = '', $order_by = 'name', $limit = '', &$total_rows = 0, $user = 0) {
	if ($limit != '') {
		$limit = "LIMIT $limit";
	}

	if ($order_by != '') {
		$order_by = "ORDER BY $order_by";
	}

	$i          = 0;
	$sql_where1 = '';
	$sql_select = '';
	$sql_join   = '';

	if ($user == -1) {
		$auth_method = 0;
	}else{
		$auth_method = read_config_option('auth_method');
	}

	if ($auth_method != 0) {
		if ($user == 0) {
			if (isset($_SESSION['sess_user_id'])) {
				$user = $_SESSION['sess_user_id'];
			}else{
				return array();
			}
		}

		/* get policies for all groups and user */
		$policies   = db_fetch_assoc_prepared("SELECT uag.id, 'group' AS type, policy_trees FROM user_auth_group AS uag
			INNER JOIN user_auth_group_members AS uagm
			ON uag.id = uagm.group_id
			WHERE uag.enabled = 'on' AND uagm.user_id = ?", array($user));
		$policies[] = db_fetch_row_prepared("SELECT id, 'user' as type, policy_trees FROM user_auth WHERE id = ?", array($user));

		foreach($policies as $policy) {
			if ($policy['policy_trees'] == '1') {
				$sql_where1 .= (strlen($sql_where1) ? ' OR':'') . " uap$i." . $policy['type'] . "_id IS NULL";
			}elseif ($policy['policy_trees'] == '2') {
				$sql_where1 .= (strlen($sql_where1) ? ' OR':'') . " uap$i." . $policy['type'] . "_id IS NOT NULL";
			}

			$sql_join .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'group' ? 'group_':'') . "perms AS uap$i
				ON (gt.id=uap$i.item_id AND uap$i.type=2 AND uap$i." . $policy['type'] . '_id=' . $policy['id'] . ') ';

			$i++;
		}

		if (strlen($sql_where)) {
			$sql_where = 'WHERE ' . ($edit == false ? '(gt.enabled="on") AND ':'') . '(' . $sql_where . ') AND (' . $sql_where1 . ')';
		}else{
			$sql_where = 'WHERE ' . ($edit == false ? '(gt.enabled="on") AND ':'') . '(gt.enabled="on") AND (' . $sql_where1 . ')';
		}

		$sql = "SELECT id, name 
			FROM graph_tree AS gt
			$sql_join
			$sql_where
			$order_by
			$limit";

		if ($return_sql) {
			return $sql;
		}else{
			$trees = db_fetch_assoc($sql);

			$total_rows = db_fetch_cell("SELECT COUNT(gt.id) 
				FROM graph_tree AS gt
				$sql_join
				$sql_where");
		}
	}else{
		if (strlen($sql_where)) {
			$sql_where = "WHERE enabled='on' AND $sql_where";
		}else{
			$sql_where = "WHERE enabled='on'";
		}

		if ($return_sql) {
			return "SELECT id, name FROM graph_tree $sql_where $order_by";
		}else{
			$trees      = db_fetch_assoc("SELECT id, name FROM graph_tree AS gt $sql_where $order_by");
			$total_rows = db_fetch_cell("SELECT COUNT(*) FROM graph_tree AS gt $sql_where");
		}
	}

	return $trees;
}

function get_allowed_devices($sql_where = '', $order_by = 'description', $limit = '', &$total_rows = 0, $user = 0, $host_id = 0) {
	if ($limit != '') {
		$limit = "LIMIT $limit";
	}

	if ($order_by != '') {
		$order_by = "ORDER BY $order_by";
	}

	if (strlen($sql_where)) {
		$sql_where = "WHERE $sql_where";
	}

	if ($host_id > 0) {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . " h.id=$host_id";
	}

	$i          = 0;
	$sql_having = '';
	$sql_select = '';
	$sql_join   = '';

	if ($user == -1) {
		$auth_method = 0;
	}else{
		$auth_method = read_config_option('auth_method');
	}

	if ($auth_method != 0) {
		if ($user == 0) {
			if (isset($_SESSION['sess_user_id'])) {
				$user = $_SESSION['sess_user_id'];
			}else{
				return array();
			}
		}

		if (read_config_option('graph_auth_method') == 1) {
			$sql_operator = 'OR';
		}else{
			$sql_operator = 'AND';
		}

		/* get policies for all groups and user */
		$policies   = db_fetch_assoc_prepared("SELECT uag.id, 'group' AS type, 
			policy_graphs, policy_hosts, policy_graph_templates 
			FROM user_auth_group AS uag
			INNER JOIN user_auth_group_members AS uagm
			ON uag.id = uagm.group_id
			WHERE uag.enabled = 'on' AND uagm.user_id = ?", array($user));

		$policies[] = db_fetch_row_prepared("SELECT id, 'user' AS type, 
			policy_graphs, policy_hosts, policy_graph_templates 
			FROM user_auth 
			WHERE id = ?", array($user));
		
		foreach($policies as $policy) {
			if ($policy['policy_graphs'] == 1) {
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "(user$i IS NULL";
			}else{
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "(user$i IS NOT NULL";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.id=uap$i.item_id AND uap$i.type=1 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;

			if ($policy['policy_hosts'] == 1) {
				$sql_having .= " OR (user$i IS NULL";
			}else{
				$sql_having .= " OR (user$i IS NOT NULL";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.host_id=uap$i.item_id AND uap$i.type=3 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;

			if ($policy['policy_graph_templates'] == 1) {
				$sql_having .= " $sql_operator user$i IS NULL))";
			}else{
				$sql_having .= " $sql_operator user$i IS NOT NULL))";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.graph_template_id=uap$i.item_id AND uap$i.type=4 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;
		}

		$sql_having = "HAVING $sql_having";

		$host_list = db_fetch_assoc("SELECT h1.*
			FROM host AS h1
			INNER JOIN (
				SELECT DISTINCT id FROM (
					SELECT h.*, $sql_select
					FROM host AS h
					LEFT JOIN graph_local AS gl 
					ON h.id=gl.host_id 
					LEFT JOIN graph_templates_graph AS gtg 
					ON gl.id=gtg.local_graph_id 
					LEFT JOIN graph_templates AS gt 
					ON gt.id=gl.graph_template_id 
					LEFT JOIN host_template AS ht
					ON h.host_template_id=ht.id
					$sql_join
					$sql_where
					$sql_having
				) AS rs1
			) AS rs2
			ON rs2.id=h1.id
			$order_by
			$limit");

		$total_rows = db_fetch_cell("SELECT COUNT(DISTINCT id)
			FROM (
				SELECT h.id, $sql_select
				FROM host AS h 
				LEFT JOIN graph_local AS gl 
				ON h.id=gl.host_id 
				LEFT JOIN graph_templates_graph AS gtg 
				ON gl.id=gtg.local_graph_id 
				LEFT JOIN graph_templates AS gt 
				ON gt.id=gl.graph_template_id 
				LEFT JOIN host_template AS ht
				ON h.host_template_id=ht.id
				$sql_join
				$sql_where
				$sql_having
			) AS rower");
	}else{
		$host_list = db_fetch_assoc("SELECT h1.*
			FROM host AS h1
			INNER JOIN (
				SELECT DISTINCT id FROM (
					SELECT h.* 
					FROM host AS h
					LEFT JOIN graph_local AS gl 
					ON h.id=gl.host_id 
					LEFT JOIN graph_templates_graph AS gtg 
					ON gl.id=gtg.local_graph_id 
					LEFT JOIN graph_templates AS gt 
					ON gt.id=gl.graph_template_id 
					LEFT JOIN host_template AS ht
					ON h.host_template_id=ht.id
					$sql_where
				) AS rs1
			) AS rs2
			ON rs2.id=h1.id
			$order_by
			$limit");

		$total_rows = db_fetch_cell("SELECT COUNT(DISTINCT id)
			FROM (
				SELECT h.id
				FROM host AS h 
				LEFT JOIN graph_local AS gl 
				ON h.id=gl.host_id 
				LEFT JOIN graph_templates_graph AS gtg 
				ON gl.id=gtg.local_graph_id 
				LEFT JOIN graph_templates AS gt 
				ON gt.id=gl.graph_template_id 
				LEFT JOIN host_template AS ht
				ON h.host_template_id=ht.id
				$sql_where
			) AS rower");
	}

	return $host_list;
}

function get_allowed_graph_templates($sql_where = '', $order_by = 'name', $limit = '', &$total_rows = 0, $user = 0, $graph_template_id = 0) {
	if ($limit != '') {
		$limit = "LIMIT $limit";
	}

	if ($order_by != '') {
		$order_by = "ORDER BY $order_by";
	}

	if (strlen($sql_where)) {
		$sql_where = "WHERE $sql_where";
	}

	if ($graph_template_id > 0) {
		$sql_where .= (strlen($sql_where) ? ' AND ':'WHERE ') . " gl.graph_template_id=$graph_template_id";
	}

	$i          = 0;
	$sql_having = '';
	$sql_select = '';
	$sql_join   = '';

	if ($user == -1) {
		$auth_method = 0;
	}else{
		$auth_method = read_config_option('auth_method');
	}

	if ($auth_method != 0) {
		if ($user == 0) {
			if (isset($_SESSION['sess_user_id'])) {
				$user = $_SESSION['sess_user_id'];
			}else{
				return array();
			}
		}

		if (read_config_option('graph_auth_method') == 1) {
			$sql_operator = 'OR';
		}else{
			$sql_operator = 'AND';
		}

		/* get policies for all groups and user */
		$policies   = db_fetch_assoc_prepared("SELECT uag.id, 'group' AS type, policy_graphs, policy_hosts, 
			policy_graph_templates FROM user_auth_group AS uag
			INNER JOIN user_auth_group_members AS uagm
			ON uag.id = uagm.group_id
			WHERE uag.enabled = 'on' AND uagm.user_id = ?", 
			array($user));

		$policies[] = db_fetch_row_prepared("SELECT id, 'user' AS type, policy_graphs, 
			policy_hosts, policy_graph_templates 
			FROM user_auth 
			WHERE id = ?", 
			array($user));
		
		foreach($policies as $policy) {
			if ($policy['policy_graph_templates'] == 1) {
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "(user$i IS NULL";
			}else{
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "(user$i IS NOT NULL";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gt.graph_template_id=uap$i.item_id AND uap$i.type=4 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;

			if ($policy['policy_graphs'] == 1) {
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "user$i IS NULL)";
			}else{
				$sql_having .= (strlen($sql_having) ? ' OR ':'') . "user$i IS NOT NULL)";
			}

			$sql_join   .= 'LEFT JOIN user_auth_' . ($policy['type'] == 'user' ? '':'group_') . "perms AS uap$i ON (gl.id=uap$i.item_id AND uap$i.type=1 AND uap$i." . $policy['type'] . "_id=" . $policy['id'] . ") ";
			$sql_select .= (strlen($sql_select) ? ', ':'') . "uap$i." . $policy['type'] . "_id AS user$i";
			$i++;

		}

		$sql_having = "HAVING $sql_having";

		$templates = db_fetch_assoc("SELECT gt1.*
			FROM graph_templates AS gt1
			INNER JOIN (
				SELECT DISTINCT graph_template_id FROM (
					SELECT gt.*, $sql_select
					FROM graph_templates_graph AS gt
					INNER JOIN graph_local AS gl
					ON gl.id=gt.local_graph_id
					INNER JOIN host AS h
					ON gl.host_id=h.id
					$sql_join
					$sql_where
					$sql_having
				) AS rs1
			) AS rs2
			ON rs2.graph_template_id=gt1.id
			$order_by
			$limit");

		$total_rows = db_fetch_cell("SELECT COUNT(id)
			FROM (
				SELECT gt.id, $sql_select
				FROM graph_templates_graph AS gt
				INNER JOIN graph_local AS gl
				ON gl.id=gt.local_graph_id
				INNER JOIN host AS h
				ON gl.host_id=h.id
				$sql_join
				$sql_where
				$sql_having
			) AS rower");
	}else{
		$templates = db_fetch_assoc("SELECT gt1.*
			FROM graph_templates AS gt1
			INNER JOIN (
				SELECT DISTINCT graph_template_id FROM (
					SELECT gt.*
					FROM graph_templates_graph AS gt
					INNER JOIN graph_local AS gl
					ON gl.id=gt.local_graph_id
					INNER JOIN host AS h
					ON gl.host_id=h.id
					$sql_where
				) AS rs1
			) AS rs2
			ON rs2.graph_template_id=gt1.id
			$order_by
			$limit");

		$total_rows = db_fetch_cell("SELECT COUNT(id)
			FROM (
				SELECT gt.id
				FROM graph_templates_graph AS gt
				INNER JOIN graph_local AS gl
				ON gl.id=gt.local_graph_id
				INNER JOIN host AS h
				ON gl.host_id=h.id
				$sql_where
			) AS rower");
	}

	return $templates;
}

function get_allowed_graph_templates_normalized($sql_where = '', $order_by = 'name', $limit = '', 
	&$total_rows = 0, $user = 0, $graph_template_id = 0) {

	$new_templates = array();

	$templates = get_allowed_graph_templates($sql_where, $order_by, $limit, $total_rows, $user, $graph_template_id);

	if (sizeof($templates)) {
		foreach($templates as $key => $t) {
			$dqg = db_fetch_assoc_prepared("SELECT DISTINCT sqg.id, name 
				FROM snmp_query_graph AS sqg
				INNER JOIN graph_local AS gl
				ON gl.snmp_query_graph_id=sqg.id
				WHERE sqg.graph_template_id = ? 
				" . ($sql_where != '' ? " AND ":"") . "$sql_where
				ORDER BY name", array($t['id']));

			if (sizeof($dqg)) {
				unset($templates[$key]);
				foreach($dqg as $t) {
					$new_templates[] = array('id' => 'dq_' . $t['id'], 'name' => $t['name']);
				}
			}else{
				$new_templates[] = array('id' => 'cg_' . $t['id'], 'name' => $t['name']);
			}
		}
	}

	return $new_templates;
}

/* get_host_array - returns a list of hosts taking permissions into account if necessary
   @returns - (array) an array containing a list of hosts */
function get_host_array() {
	$hosts = get_allowed_devices();

	foreach($hosts as $host) {
		$return_devices[] = $host['description'] . ' (' . $host['hostname'] . ')';
	}

	return $return_devices;
}

function get_allowed_ajax_hosts($include_any = true, $include_none = true, $sql_where = '') {
	$return    = array();

	$term      = get_filter_request_var('term', FILTER_CALLBACK, array('options' => 'sanitize_search_string'));
	if ($term != '') {
		$sql_where .= (strlen($sql_where) ? ' AND ':'') . "hostname LIKE '%$term%' OR description LIKE '%$term%' OR notes LIKE '%$term%'";
	}

	$hosts = get_allowed_devices($sql_where, 'description', 30);

	if (get_request_var('term') == '') {
		if ($include_any) {
			$return[] = array('label' => 'Any', 'value' => 'Any', 'id' => '-1');
		}
		if ($include_none) {
			$return[] = array('label' => 'None', 'value' => 'None', 'id' => '0');
		}
	}

	if (sizeof($hosts)) {
	foreach($hosts as $host) {
		$return[] = array('label' => $host['description'], 'value' => $host['description'], 'id' => $host['id']);
	}
	}

	print json_encode($return);
}

function secpass_login_process () {
	$users = db_fetch_assoc('SELECT username FROM user_auth WHERE realm = 0');
	$username = sanitize_search_string(get_nfilter_request_var('login_username'));

	# Mark failed login attempts
	if (read_config_option('secpass_lockfailed') > 0) {
		$max = intval(read_config_option('secpass_lockfailed'));
		if ($max > 0) {
			$p = get_nfilter_request_var('login_password');
			foreach ($users as $fa) {
				if ($fa['username'] == $username) {

					$user = db_fetch_assoc_prepared("SELECT * FROM user_auth WHERE username = ? AND realm = 0 AND enabled = 'on'", array($username));
					if (isset($user[0]['username'])) {
						$user = $user[0];
						$unlock = intval(read_config_option('secpass_unlocktime'));
						if ($unlock > 1440) $unlock = 1440;

						if ($unlock > 0 && (time() - $user['lastfail'] > 60 * $unlock)) {
							db_execute_prepared("UPDATE user_auth SET lastfail = 0, failed_attempts = 0, locked = '' WHERE username = ? AND realm = 0 AND enabled = 'on'", array($username));
							$user['failed_attempts'] = $user['lastfail'] = 0;
							$user['locked'] == '';
						}

						if ($user['password'] != md5($p)) {
							$failed = $user['failed_attempts'] + 1;
							if ($failed >= $max) {
								db_execute_prepared("UPDATE user_auth SET locked = 'on' WHERE username = ? AND realm = 0 AND enabled = 'on'", array($username));
								$user['locked'] = 'on';
							}
							$user['lastfail'] = time();
							db_execute_prepared("UPDATE user_auth SET lastfail = ?, failed_attempts = ? WHERE username = ? AND realm = 0 AND enabled = 'on'", array($user['lastfail'], $failed, $username));

							if ($user['locked'] != '') {
								auth_display_custom_error_message('This account has been locked.');
								exit;
							}
							return false;
						}
						if ($user['locked'] != '') {
							auth_display_custom_error_message('This account has been locked.');
							exit;						}
					}
				}
			}
		}
	}

	# Check if old password doesn't meet specifications and must be changed
	if (read_config_option('secpass_forceold') == 'on') {
		$p = get_nfilter_request_var('login_password');
		$error = secpass_check_pass($p);
		if ($error != '') {
			foreach ($users as $fa) {
				if ($fa['username'] == $username) {
					db_execute_prepared("UPDATE user_auth SET must_change_password = 'on' WHERE username = ? AND password = ? AND realm = 0 AND enabled = 'on'", array($username, md5(get_nfilter_request_var('login_password'))));
					return true;
				}
			}
		}
	}
	# Set the last Login time
	if (read_config_option('secpass_expireaccount') > 0) {
		$p = get_nfilter_request_var('login_password');
		foreach ($users as $fa) {
			if ($fa['username'] == $username) {
				db_execute_prepared("UPDATE user_auth SET lastlogin = ? WHERE username = ? AND password = ? AND realm = 0 AND enabled = 'on'", array(time(), $username, md5(get_nfilter_request_var('login_password'))));
			}
		}
	}
	return true;
}

function secpass_check_pass($p) {
	$minlen = read_config_option('secpass_minlen');
	
	$reqmixcase = (read_config_option('secpass_reqmixcase') == 'on' ? true : false);
	$reqnum     = (read_config_option('secpass_reqnum') == 'on' ? true : false);
	$reqspec    = (read_config_option('secpass_reqspec') == 'on' ? true : false);
	if (strlen($p) < $minlen) {
		return 'Password must be at least ' . $minlen . ' characters!';
	}

	if ($reqnum && str_replace(array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9'), '', $p) == $p) {
		return 'Your password must contain at least 1 numerical character!';
	}

	if ($reqmixcase && strtolower($p) == $p) {
		return 'Your password must contain a mix of lower case and upper case characters!';
	}

	if ($reqspec && str_replace(array('~', '`', '!', '@', '#', '$', '%', '^', '&', '*', '(', ')', '-', '_', '+', '=', '[', '{', ']', '}', ';', ':', '<', ',', '.', '>', '?', '|', '/', '\\'), '', $p) == $p) {
		return 'Your password must contain at least 1 special character!';
	}

	return 'ok';
}

function secpass_check_history($id, $p) {
	$history = intval(read_config_option('secpass_history'));
	if ($history > 0) {
		$p = md5($p);
		$user = db_fetch_row_prepared("SELECT password, password_history FROM user_auth WHERE id = ? AND realm = 0 AND enabled = 'on'", array($id));
		if ($p == $user['password']) {
			return false;
		}
		$passes = explode('|', $user['password_history']);
		// Double check this incase the password history setting was changed
		while (count($passes) > $history) {
			array_shift($passes);
		}
		if (!empty($passes) && in_array($p, $passes)) {
			return false;
		}
	}
	return true;
}

function rsa_check_keypair() {
	global $config;
	
	set_include_path($config['include_path'] . '/phpseclib/');
	include('Crypt/Base.php');
	include('Math/BigInteger.php');
	include('Crypt/Hash.php');
	include('Crypt/RSA.php');
	include('Crypt/Rijndael.php');
	include('Crypt/AES.php');
	
	$public_key = read_config_option('rsa_public_key');

	if(!$public_key) {
		$rsa = new phpseclib\Crypt\RSA();
		$keys = $rsa->createKey(2048);
		$rsa->loadKey($keys['publickey']);
		$fingerprint = $rsa->getPublicKeyFingerprint();

		db_execute_prepared("INSERT INTO settings (`name`, `value`) VALUES ('rsa_public_key', '" . $keys['publickey'] . "'),('rsa_private_key', '" . $keys['privatekey'] . "'),('rsa_fingerprint', '" . $fingerprint . "')");
	}
}

/* reset_group_perms - sets a flag for all users of a group logged in that their perms need to be reloaded from the database
   @arg $user_id - (int) the id of the current user
   @returns - null */
function reset_group_perms($group_id) {
	$users = array_rekey(db_fetch_assoc_prepared('SELECT user_id FROM user_auth_group_members WHERE group_id = ?', array($group_id)), 'user_id', 'user_id');
	if (sizeof($users)) {
		db_execute('UPDATE user_auth SET reset_perms=FLOOR(RAND() * 4294967295) + 1 WHERE id IN (' . implode(',', $users) . ')');
	}
}

/* reset_user_perms - sets a flag for all users logged in as this user that their perms need to be reloaded from the database
   @arg $user_id - (int) the id of the current user
   @returns - null */
function reset_user_perms($user_id) {
	db_execute_prepared('UPDATE user_auth SET reset_perms=FLOOR(RAND() * 4294967295) + 1 WHERE id = ?', array($user_id));
}

/* user_perms_valid - checks to see if the admin has changed users permissions
   @arg $user_id - (int) the id of the current user
   @returns - true if still valid, false otherwise */
function user_perms_valid($user_id) {
	global $config;

	static $valid = 'null';

	if ($valid === 'null') {
		$valid = true;

		if (version_compare($config['cacti_db_version'], '1.0.0') >= 0) {
			$key   = db_fetch_cell_prepared('SELECT reset_perms FROM user_auth WHERE id = ?', array($user_id));

			if (isset($_SESSION['sess_user_perms_key'])) {
				if ($key != $_SESSION['sess_user_perms_key']) {
					$valid = false;
				}
			}

			$_SESSION['sess_user_perms_key'] = $key;
		}else{
			$_SESSION['sess_user_perms_key'] = $valid;
		}
	}else{
		$valid = true;
		$_SESSION['sess_user_perms_key'] = $valid;
	}

	return $valid;
}

