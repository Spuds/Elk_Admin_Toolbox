<?php

/**
 * Admin Toolbox Integration Hooks
 *
 * @package Admin Toolbox
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://www.mozilla.org/MPL/1.1/.
 *
 * @version 1.0
 */

/**
 * @param type $admin_areas
 */
function iaa_admintoolbox(&$admin_areas)
{
	// Admin Hook, integrate_admin_areas, called from Admin.php
	// used to add/modify admin menu areas
	global $txt;

	loadLanguage('AdminToolbox');

	// our admintoolbox tab
	$admin_areas['maintenance']['areas']['toolbox'] = array(
		'controller' => 'Toolbox_Controller',
		'function' => 'action_index',
		'permission' => array('admin_forum'),
		'label' => $txt['toolbox_title'],
		'file' => 'AdminToolbox.controller.php',
		'icon' => 'toolbox.png',
	);
}

/**
 * Load Member Data hook, integrate_load_member_data, Called from load.php
 *
 * Used to add columns / tables to the query so additional data can be loaded for a set
 *
 * @param string $select_columns
 * @param mixed[] $select_tables
 * @param string $set
 */
function ilmd_admintoolbox(&$select_columns, &$select_tables, $set)
{
	if ($set == 'profile' || $set == 'normal')
	{
		$select_columns .= ',mem.mentions, mem.unread_messages, mem.new_pm, mem.avatar';
	}
}