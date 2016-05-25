<?php
/*
Group Order Plugin for MyBB
Copyright (C) 2013 Dieter Gobbers

The MIT License (MIT)

Copyright (c) 2016 Dieter Gobbers

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

*/

/* Exported by Hooks plugin Wed, 28 Aug 2013 10:37:27 GMT */


if (!defined('IN_MYBB'))
{
	die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

/* --- Plugin API: --- */

function grouporder_info()
{
	return array(
		'name' => 'Group Sort',
		'description' => 'Sort the usergroups and keep the sequence consistent for all users',
		'website' => 'http://opt-community.de/',
		'author' => 'Dieter Gobbers (@TerranUlm)',
		'authorsite' => 'http://opt-community.de/',
		'version' => '1.0.1',
		'guid' => '',
		'compatibility' => '16*'
	);
}

function grouporder_activate()
{
	global $db, $lang, $cache;

	grouporder_deactivate();
	
	$lang->load('grouporder');
	
	$updated_record = array(
		"enabled" => intval(1)
	);
	$db->update_query(
		'tasks',
		$updated_record,
		"title='{$db->escape_string($lang->grouporder_title)}'"
	);

	$cache->update_tasks();
}

function grouporder_deactivate()
{
	global $db, $lang, $cache;

	$lang->load('grouporder');
	
	$updated_record = array(
		"enabled" => intval(0)
	);
	$db->update_query(
		'tasks',
		$updated_record,
		"title='{$db->escape_string($lang->grouporder_title)}'"
	);
	
	$cache->update_tasks();
}

function grouporder_is_installed()
{
	global $db, $lang;
	
	$lang->load('grouporder');

	$query=$db->simple_select(
		'tasks',
		'*',
		"title='{$db->escape_string($lang->grouporder_title)}'"
	);
	$result=$db->fetch_array($query);
	$db->free_result($query);
	
	return(!empty($result));
}

function grouporder_install()
{
	global $db, $lang, $cache;
	
	$lang->load('grouporder');
	
	// create task
	require_once MYBB_ROOT."/inc/functions_task.php";

	$new_task = array(
		"title" => $db->escape_string($lang->grouporder_title),
		"description" => $db->escape_string($lang->grouporder_task_description),
		"file" => $db->escape_string('grouporder'),
		"minute" => $db->escape_string('27'),
		"hour" => $db->escape_string('4'),
		"day" => $db->escape_string('*'),
		"month" => $db->escape_string('*'),
		"weekday" => $db->escape_string('*'),
		"enabled" => intval(0),
		"logging" => intval(1)
	);

	$new_task['nextrun'] = fetch_next_run($new_task);
	$tid = $db->insert_query("tasks", $new_task);
	$cache->update_tasks();

}

function grouporder_uninstall()
{
	global $db, $lang, $cache;
	
	$lang->load('grouporder');
	
	$db->delete_query("tasks", "title='{$db->escape_string($lang->grouporder_title)}'");
	$cache->update_tasks();

}

/* --- Hooks: --- */

/* --- Hook #1 - Add Grouporder Tab --- */

$plugins->add_hook('admin_user_groups_edit_graph_tabs','grouporder_admin_user_groups_edit_graph_tabs_1',1);

function grouporder_admin_user_groups_edit_graph_tabs_1(&$tabs)
{
	global $lang;
	$lang->load('grouporder');
	$tabs["grouporder"] = $lang->grouporder_title;
	
	return $tabs;
}

/* --- Hook #2 - Add Teamspeak Tab Content --- */

$plugins->add_hook('admin_user_groups_edit_graph','grouporder_admin_user_groups_edit_graph_2',1);

function grouporder_admin_user_groups_edit_graph_2()
{
	global $lang, $form, $mybb;

	$lang->load('grouporder');

	echo "<div id=\"tab_grouporder\">";

	$form_container = new FormContainer($lang->grouporder_title);

	$form_container->output_row($lang->grouporder_rank, $lang->grouporder_rank_description, $form->generate_text_box('disporder', $mybb->input['disporder'], array('id' => 'disporder')), 'servergroupid');

	$form_container->end();

	echo "</div>";
	
	grouporder_reorder_all_users();
}

/* --- Hook #6 - admin_user_groups_edit_commit --- */

$plugins->add_hook('admin_user_groups_edit_commit','grouporder_admin_user_groups_edit_commit_6',10);

function grouporder_admin_user_groups_edit_commit_6()
{
	global $mybb, $db, $updated_group, $cache;

	$updated_group['disporder']  = intval($mybb->input['disporder']);

	$db->update_query("usergroups", $updated_group, "gid='{$usergroup['gid']}'");
	
	grouporder_reorder_groups();
	
	// Update the caches
	$cache->update_usergroups();
}

$plugins->add_hook('admin_user_groups_start_commit','grouporder_admin_user_groups_start_commit',10);

function grouporder_admin_user_groups_start_commit()
{
	grouporder_reorder_groups();
}

/* --- Hook #23 - set disporder for groups on creation --- */

$plugins->add_hook('admin_user_groups_add_commit','grouporder_admin_user_groups_add_commit_23',10);

function grouporder_admin_user_groups_add_commit_23()
{
	global $new_usergroup;

	$new_usergroup['disporder']=10000;
}

function grouporder_reorder_groups()
{
	global $db;
	
	$query = $db->simple_select('usergroups', '*', '', array(
		'order_by' => 'disporder',
		'order_dir' => 'ASC'
	));
	
	$count = 1;
	while ($row = $db->fetch_array($query))
	{
		$updated_record=array(
			'disporder' => intval($count)
		);
		$db->update_query(
			'usergroups',
			$updated_record,
			'gid='.intval($row['gid'])
		);
		$count++;
	}
	$db->free_result($query);
}

function grouporder_reorder_all_users()
{
	global $db, $mybb;
	
	$query=$db->simple_select(
		'usergroups',
		'*',
		'',
		array(
			'order_by' => 'disporder',
			'order_dir' => 'ASC'
		)
	);
	$all_usergroups=array();
	while($usergroup = $db->fetch_array($query))
	{
		$all_usergroups[$usergroup['gid']]=$usergroup['disporder'];
	}
	$db->free_result($query);

	$query=$db->simple_select(
		'users',
		'uid'
	);
	while($uid = $db->fetch_field($query, 'uid'))
	{
		grouporder_reorder_user($uid, $all_usergroups);
	}
	$db->free_result($query);
}

function grouporder_reorder_user($uid, $all_usergroups)
{
	global $db;
	
	// get the users' groups
	$query = $db->simple_select(
		'users',
		'*',
		'uid='.intval($uid)
	);
	$user=$db->fetch_array($query);
	$db->free_result($query);
	
	// build usergroup array
	$usergroups = array_unique (array_merge(array($user['usergroup']), explode(',', $user['additionalgroups'])));
	sort($usergroups);
	if (!in_array(2,$usergroups)) // user is not in the "registered" group
	{
		if(!in_array(5,$usergroups) && !in_array(7,$usergroups) && !in_array(1,$usergroups)) // should the user be in the "registered" group? (not banned, not awaiting activation and not unregistered
		{
			$usergroups[]=2;
		}
	}
	
	// reorder the usergroups
	$new_usergroups=array();
	foreach($all_usergroups as $usergroup => $order)
	{
		if (in_array($usergroup, $usergroups))
		{
			$new_usergroups[]=$usergroup;
		}
	}
	
	// update user data
	$primarygroup=array_shift($new_usergroups);
	$usergroups=implode(',', $new_usergroups);
	$new_record=array(
		'usergroup' => $primarygroup,
		'additionalgroups' => $usergroups,
		'displaygroup' => 0
	);
	$db->update_query(
		'users',
		$new_record,
		'uid='.intval($uid)
	);
}

/* Exported by Hooks plugin Wed, 28 Aug 2013 10:37:27 GMT */
?>
