<?php
/**
 *
 * Titania Topic Transfer extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\titaniatopictransfer\event;

use phpbb\auth\auth;
use phpbb\controller\helper;
use phpbb\event\data;
use phpbb\extension\manager;
use phpbb\language\language;
use phpbb\request\request_interface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds "Transfer to Titania support" to a topic's Quick-mod tools and sends
 * that action to the transfer page.
 */
final class moderation implements EventSubscriberInterface
{
	public function __construct(
		private auth $auth,
		private language $language,
		private manager $extensions,
		private request_interface $request,
		private helper $helper
	)
	{
	}

	public static function getSubscribedEvents(): array
	{
		return [
			'core.viewtopic_add_quickmod_option_before'	=> 'add_quickmod',
			'core.modify_quickmod_options'				=> 'dispatch_quickmod',
		];
	}

	/**
	 * Offer the Quick-mod option to moderators who can move the topic and
	 * moderate Titania posts, while Titania is enabled.
	 *
	 * @param data $event quickmod_array, forum_id and topic_data from viewtopic.php
	 */
	public function add_quickmod(data $event): void
	{
		if (!$this->extensions->is_enabled('phpbb/titania')
			|| !$this->auth->acl_get('m_move', (int) $event['forum_id'])
			|| !$this->auth->acl_get('u_titania_mod_post_mod')
			|| (int) $event['topic_data']['topic_status'] === ITEM_MOVED)
		{
			return;
		}

		$this->language->add_lang('transfer', 'phpbbmodders/titaniatopictransfer');
		$options = $event['quickmod_array'];
		$options['titania_transfer'] = ['TITANIATOPICTRANSFER', true];
		$event['quickmod_array'] = $options;
	}

	/**
	 * Send the Quick-mod action to the transfer page, which checks permissions again.
	 *
	 * @param data $event action from mcp.php
	 */
	public function dispatch_quickmod(data $event): void
	{
		if ($event['action'] === 'titania_transfer')
		{
			redirect($this->helper->route('phpbbmodders_titaniatopictransfer_transfer', [
				'topic_id' => $this->request->variable('t', 0),
			], false));
		}
	}
}
