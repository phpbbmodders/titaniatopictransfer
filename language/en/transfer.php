<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

/**
 * DO NOT CHANGE
 */
if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'TITANIATOPICTRANSFER'						=> 'Transfer to Titania support',
	'TITANIATOPICTRANSFER_POSTS'				=> 'Posts to transfer',
	'TITANIATOPICTRANSFER_POSTS_EXPLAIN'		=> 'Select every post to move the whole topic. Selecting the opening post with only some replies copies the opening post and leaves it in the forum topic, which is then locked.',
	'TITANIATOPICTRANSFER_POST_BY'				=> '%1$s by %2$s, %3$s (post %4$d)',
	'TITANIATOPICTRANSFER_DESTINATION'			=> 'Destination',
	'TITANIATOPICTRANSFER_CONTRIB_ID'			=> 'Contribution ID',
	'TITANIATOPICTRANSFER_CONTRIB_ID_EXPLAIN'	=> 'The ID of an approved, public contribution in the Customisation Database.',
	'TITANIATOPICTRANSFER_TOPIC_ID'				=> 'Existing support topic ID',
	'TITANIATOPICTRANSFER_TOPIC_ID_EXPLAIN'		=> 'Leave empty to start a new support topic.',
	'TITANIATOPICTRANSFER_SOURCE'				=> 'Forum topic',
	'TITANIATOPICTRANSFER_COPY_TOPIC'			=> 'Copy the whole topic and close the original',
	'TITANIATOPICTRANSFER_REASON'				=> 'Closing reason',
	'TITANIATOPICTRANSFER_REASON_EXPLAIN'		=> 'Posted in the forum topic with a link to the support topic. Needed when the forum topic is closed.',
	'TITANIATOPICTRANSFER_OPTIONS'				=> 'Options',
	'TITANIATOPICTRANSFER_COPY_SUBSCRIPTIONS'	=> 'Carry subscriptions over when transferring the whole topic',
	'TITANIATOPICTRANSFER_COPY_TRACKING'		=> 'Carry read markers over when transferring the whole topic to a new support topic',
	'TITANIATOPICTRANSFER_NOTIFY_AUTHOR'		=> 'Send a private message to the topic starter',
	'TITANIATOPICTRANSFER_MOVED_NOTICE'			=> 'This topic has moved to Titania support.',

	'TITANIATOPICTRANSFER_PM_SUBJECT'			=> 'Forum posts transferred to Titania support',
	'TITANIATOPICTRANSFER_PM_MESSAGE'			=> 'Posts from your forum topic were transferred to Titania support.',

	'TITANIATOPICTRANSFER_ERR_FAILED'			=> 'The transfer could not be completed. Nothing was changed.',
	'TITANIATOPICTRANSFER_ERR_SOURCE_RESTRICTED'	=> 'The forum this topic is in is not public, so its posts cannot be moved to public support.',
	'TITANIATOPICTRANSFER_ERR_UNAPPROVED'		=> 'Only approved posts can be transferred to public support.',
	'TITANIATOPICTRANSFER_ERR_TOPIC_UNAVAILABLE'	=> 'This topic cannot be transferred.',
	'TITANIATOPICTRANSFER_ERR_NO_OPENING_POST'	=> 'The topic has no opening post.',
	'TITANIATOPICTRANSFER_ERR_NO_SELECTION'		=> 'Select posts from this topic.',
	'TITANIATOPICTRANSFER_ERR_COPY_WHOLE_TOPIC'	=> 'Copy and close needs every post in the topic to be selected.',
	'TITANIATOPICTRANSFER_ERR_REASON'			=> 'Enter a closing reason.',
	'TITANIATOPICTRANSFER_ERR_CONTRIB'			=> 'Choose an approved, public contribution.',
	'TITANIATOPICTRANSFER_ERR_DESTINATION'		=> 'Choose an unlocked, public support topic of that contribution.',
	'TITANIATOPICTRANSFER_ERR_ATTACHMENT_TYPE'	=> 'Titania support does not accept the file type of one of the selected attachments.',
	'TITANIATOPICTRANSFER_ERR_ATTACHMENT_MISSING'	=> 'The attachment records of a selected post are missing.',
]);
