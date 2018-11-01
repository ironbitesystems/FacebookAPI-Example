<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

//Facebook SDK for PHP
require_once (get_stylesheet_directory().'/includes/facebook/autoload.php');

class IM_Facebook
{
	
	# Facebook Object for connection
	private $fb;
	
	# FB USER ID
	public $profile_id;
	
	public $group_id;
	
	# FB ALBUM ID
	public $album_id;
	
	# ALBUM NAME
	public $album_name;
	
	# FB ACCESS TOKEN
	public $access_token = FALSE;
	
	# USER TIMEZONE
	public $timezone;
	
	# SEARCH TERM FOR FB COMMENTS
	public $search_term = 'sold';
	
	# FB PARAMS FOR REQUESTS
	public $params = array();
	
	# AUTHOR OF COMMENT
	public $item_author;

	# SEARCH TYPE (i.e. keyword)
	public $search_type;
	
	# ARRAY OF FB ALBUMS
	public $albums = array();
	
	# ARRAY OF FB PHOTO IDs
	public $photo_ids = array();
	
	# ARRAY OF FB COMMENTS ON PHOTOs
	public $comments = array();
	
	# GENERAL ERROR
	private $error = array();
	
 	function __construct()
	{
		
		$current_user = wp_get_current_user();
		
		$this->profile_id = get_user_meta($current_user->ID,'_fb_id',true);
		
		$this->access_token = get_user_meta($current_user->ID,'_fb_token',true);
		
		$this->timezone = get_user_meta($current_user->ID,'_timezone',true);

		$this->fb = new Facebook\Facebook([
		  'app_id' => 'APP_ID',# TODO: CHANGE TO APP ID FROM FACEBOOK DEVELOPER
		  'app_secret' => 'APP_SECRET',# TODO: CHANGE TO APP SECRET FROM FACEBOOK DEVELOPER
		  'default_graph_version' => 'v2.5',
		]);
		
		
		add_action('wp_ajax_facebook_comments', array($this,'build_comments_table'));
		
		add_action('wp_ajax_fb-search-comments',array($this,'get_fb_comments'));
		
		add_action('wp_ajax_fb-sale', array($this,'add_sale'));
		
		add_action('wp_ajax_process_upload',array($this,'process_upload'));
		
		add_action('wp_ajax_get-mapping',array($this,'build_album_mapping'));
				
		add_action('template_redirect', array($this,'redirect_facebook_page'));

		add_shortcode('facebook',array($this,'build_facebook'));
		
		
	}
	
	# Set fb access token for requests
	function set_access_token()
	{
		
		$this->fb->setDefaultAccessToken($this->access_token);
		
	}
	
	# log fb errors for debugging
	function capture_error($function, $type, $error)
	{
		
		global $wpdb;
		
		$current_user = wp_get_current_user();
		
		$wpdb->insert(
			$wpdb->prefix.'facebook_errors',
			array(
				'user_id' => $current_user->ID,
				'function' => $function,
				'type' => $type,
				'error' => $error
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s'
			)
		);
		
		
	}
	
	# Login to facebook
	function facebook_login()
	{
		
		if ($this->access_token):

			echo '<p>You are connected to Facebook!<br>Facebook ID: '.$this->profile_id.'</p>';

		else:

			$helper = $this->fb->getRedirectLoginHelper();

			$permissions = ['email','user_managed_groups','publish_actions','user_photos']; // Optional permissions
			$loginUrl = $helper->getLoginUrl('CALLBACK_URL', $permissions); # TODO: CHANGE CALLBACK URL TO YOUR SITE CALLBACK PAGE

			echo '<a href="'.htmlspecialchars($loginUrl).'">Log in with Facebook!</a>';

		endif;
		
	}
	
	# Record access token and fb user id
	function capture_access_token()
	{
		
		$current_user = wp_get_current_user();

		$helper = $this->fb->getRedirectLoginHelper();
		
		try
		{

			$accessToken = $helper->getAccessToken();

			$oAuth2Client = $this->fb->getOAuth2Client();

			// Exchanges a short-lived access token for a long-lived one
			$this->access_token = $oAuth2Client->getLongLivedAccessToken($accessToken);
			
			update_user_meta($current_user->ID, '_fb_token', $this->access_token);
			
			$fb_id = $this->get_user_profile_id();
			
			update_user_meta($current_user->ID, '_fb_id', $fb_id);

		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('capture_access_token', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('capture_access_token', 'sdk', $e->getMessage());
			return false;
			
		}
		
	}
	
	function build_facebook($atts)
	{

		$atts = shortcode_atts( array(
			'type' => ''
		), $atts, 'facebook');
		
		if ($this->access_token):
		
			switch ($atts['type']):

				case 'comments':
		
					if ($this->access_token):

						ob_start();

						get_template_part('template-parts/facebook','comments');

						return ob_get_clean();

					else:

						return '<div class="center error">Please connect with Facebook to use this feature</div>';

					endif;
					break;

				case 'saved-comments':

					ob_start();

					get_template_part('template-parts/facebook','saved-comments');

					return ob_get_clean();
		
					break;

				case 'sales':

					ob_start();
		
					get_template_part('template-parts/facebook','sales');

					return ob_get_clean();
		
					break;

				case 'vip':

					ob_start();

					get_template_part('template-parts/facebook','sales-form');

					return ob_get_clean();
		
					break;

				case 'partner':

					ob_start();

					get_template_part('template-parts/facebook','partner-sales-form');

					return ob_get_clean();
		
					break;

				case 'search':

					ob_start();
		
					get_template_part('template-parts/facebook','comment-form');

					return ob_get_clean();
		
					break;

			endswitch;
		
		else:
		
			return '<div class="center error">Please connect with Facebook to use this feature</div>';
		
		endif;
		
	}

	function getGroups()
	{
		
		try
		{
			
		  $response = $this->fb->get('/'.$this->profile_id.'/groups', $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('getGroups', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('getGroups', 'sdk', $e->getMessage());
			return false;
			
		}

		$items = $response->getGraphEdge();
		
		$groups = array();
		
		foreach ($items as $item):
			
			$groups[] = (object)array(
				'id' => $item->getField('id'),
				'name' => $item->getField('name')
			);
			
		endforeach;
		
		return $groups;
		
	}

	function getGroup()
	{
		
		try
		{
			
		  $response = $this->fb->get('/'.$this->group_id, $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('getGroup', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('getGroup', 'sdk', $e->getMessage());
			return false;
			
		}

		$item = $response->getGraphObject();
			
		$group = (object)array(
			'id' => $item->getField('id'),
			'name' => $item->getField('name')
		);
		
		return $group;
		
	}

	function getAlbums($after = '')
	{
		
		try
		{
			
		  	//$response = $this->fb->get('/'.$this->group_id.'/albums', $this->access_token);
			
			if ($after != '')
		  		$response = $this->fb->get('/'.$this->group_id.'/albums?after='.$after, $this->access_token);
			else
		  		$response = $this->fb->get('/'.$this->group_id.'/albums', $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			
			echo 'Response error: '.$e->getMessage();
			
			$this->capture_error('getAlbums', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			echo 'SDK error: '.$e->getMessage();
			
			$this->capture_error('getAlbums', 'sdk', $e->getMessage());
			return false;
			
		}

		$items = $response->getGraphEdge();
		
		$metaData = $items->getMetaData();
		
		if (isset($metaData['paging']))
			$after = $metaData['paging']['cursors']['after'];
		else
			$after = false;
		
		$albums = array();
		
		foreach ($items as $item):
			
			$this->albums[] = (object)array(
				'id' => $item->getField('id'),
				'name' => $item->getField('name')
			);
			
		endforeach;
		
		if ($after)
			return $this->getAlbums($after);
		
		//return $albums;
		
	}

	function getAlbum($album_id)
	{
		
		try
		{
			
		  $response = $this->fb->get('/'.$album_id.'/', $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			echo $e->getMessage();
			$this->capture_error('getAlbum', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			echo $e->getMessage();
			$this->capture_error('getAlbum', 'sdk', $e->getMessage());
			return false;
			
		}

		$items = $response->getGraphNode();
		
		try
		{
			
			$this->params = array(
				'cover' => array(
					'url' => 'https://scontent.fapa1-2.fna.fbcdn.net/v/t1.0-9/14937367_10100359331294731_5665184744595493317_n.jpg?oh=48da3ce5de316e5df3fb4d9805221954&oe=58CA3B1D',
					'message' => 'Testing category - testing',
					'no_story' => false
				)
			);
			
		  $response = $this->fb->post('/'.$album_id, $this->params, $this->access_token);
			
			print_r($response);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			echo $e->getMessage();
			
			$this->capture_error('createAlbum', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			echo $e->getMessage();
			
			$this->capture_error('createAlbum', 'sdk', $e->getMessage());
			return false;
			
		}

		$response = $response->getGraphEdge();
		
		return $response;
		
	}

	function getAlbumPhotos($album_id,$after = '')
	{
		
		try
		{
			
			if ($after != '')
		  		$response = $this->fb->get('/'.$album_id.'/photos?fields=picture,id,images,from,link,name&type=uploaded&limit=100&after='.$after, $this->access_token);
			else
		  		$response = $this->fb->get('/'.$album_id.'/photos?fields=picture,id,images,from,link,name&type=uploaded&limit=100', $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('getAlbumPhotos', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('getAlbumPhotos', 'sdk', $e->getMessage());
			return false;
			
		}

		$items = $response->getGraphEdge();
		
		$metaData = $items->getMetaData();
		
		if (isset($metaData['paging']))
			$after = $metaData['paging']['cursors']['after'];
		else
			$after = false;
		
		foreach ($items as $detail):
		
			$from = $detail->getField('from');
			$from_id = $from->getField('id');
			$from_name = $from->getField('name');
		
			if ($from && $this->item_author == 'me'):
		
				if ($from_id != $this->profile_id)
					continue;
		
			endif;
			
			$images = $detail->getField('images');
			
			$message = $detail->getField('name');
		
			$product_id = '';
		
			if (preg_match('/ID: ([\d]+)/',$message,$sku))
				$product_id = $sku[1];
			
			$this->photo_ids[] = (object)array(
				'from' => $from_id,
				'name' => $from_name,
				'product_id' => $product_id,
				'album_name' => $this->album_name,
				'picture' => $detail->getField('picture'),
				'link' => $detail->getField('link'),
				'photo_id' => $detail->getField('id'),
				'url' => $images[3]->getField('source')
			);
			
		endforeach;
		
		if ($after)
			return $this->getAlbumPhotos($album_id,$after);
		
	}
	
	function delete_albums($albums = array())
	{
		
		$this->getAlbums();

		$albums = $this->albums;

		$album_photos = array();

		foreach ($albums as $album):

			$this->getAlbumPhotos($album->id);

			$album_photos[] = array(
				'name' => $album->name,
				'photos' => $this->photo_ids
			);

			$this->photo_ids = array();

		endforeach;
		
		print_r($album_photos);
		
		try
		{
			
			$response = $this->fb->post('/1783277425256249?method=delete', $this->params, $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			echo 'Graph returned an error: ' . $e->getMessage();
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			echo 'Facebook SDK returned an error: ' . $e->getMessage();
			
		}
		
		print_r($albums);
		
	}
	
	function delete_photos($albums = array())
	{
		
		$this->getAlbums();

		$albums = $this->albums;

		$album_photos = array();

		foreach ($albums as $album):

			$this->getAlbumPhotos($album->id);

			$album_photos[] = array(
				'name' => $album->name,
				'photos' => $this->photo_ids
			);

			$this->photo_ids = array();

		endforeach;

		$count = 0;

		$delete_batch = array();

		foreach ($album_photos as $album):
		
			if (empty($album['photos']))
				continue;

			$delete_batch[$count]['name'] = $album['name'];

			foreach ($album['photos'] as $details)
				$delete_batch[$count]['requests'][] = $this->fb->request('DELETE', '/'.$details->photo_id, ['pid' => $details->photo_id]);
		
			$count++;

		endforeach;
		
		$upload_count = 1;

		foreach ($delete_batch as $album):
		
			if ($upload_count == 2)
				break;
		
			$requests = $album['requests'];

			if (count($requests) > 50)
				$photo_chunks = array_chunk($requests, 50, true);
			else
				$photo_chunks = array(0 => $requests);
		
			foreach ($photo_chunks as $chunk):

				try {

				  $responses = $this->fb->sendBatchRequest($chunk);

				}
				catch(Facebook\Exceptions\FacebookResponseException $e)
				{

					$errors[] = array(
						'type' => 'response',
						'message' => $e->getMessage()
					);

					echo 'Graph returned an error: ' . $e->getMessage();
					//$this->capture_error('BatchUpload', 'response', $e->getMessage());
					continue;

				}
				catch(Facebook\Exceptions\FacebookSDKException $e)
				{

					$errors[] = array(
						'type' => 'sdk',
						'message' => $e->getMessage()
					);

					echo 'Facebook SDK returned an error: ' . $e->getMessage();
					//$this->capture_error('BatchUpload', 'sdk', $e->getMessage());
					continue;

				}

				foreach ($responses as $key => $response):

					if ($response->isError()):

						$e = $response->getThrownException();

						$errors[] = array(
							'type' => 'graph',
							'message' => $e->getMessage()
						);

					else:
		
						echo 'deleted album '.$album['name'].'<br>';

						$upload_count++;

					endif;

				endforeach;

			endforeach;

		endforeach;
		
	}

	function createAlbum()
	{
		
		try
		{
			
		  $response = $this->fb->post('/'.$this->group_id.'/albums', $this->params, $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('createAlbum', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('createAlbum', 'sdk', $e->getMessage());
			return false;
			
		}

		$response = $response->getGraphNode();
		
		return $response->getField('id');
		
	}

	function uploadPhotosToAlbum()
	{
		
		try
		{
			
		  $response = $this->fb->post('/'.$this->album_id.'/photos', $this->params, $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('uploadPhotosToAlbum', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('uploadPhotosToAlbum', 'sdk', $e->getMessage());
			return false;
			
		}

		$items = $response->getGraphNode();
		
		return $items;
		
	}

	function getPhoto($photo_id)
	{
		
		try
		{
			
		  $response = $this->fb->get('/'.$photo_id.'/?fields=images', $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('getPhoto', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('getPhoto', 'sdk', $e->getMessage());
			return false;
			
		}

		$items = $response->getGraphObject();
			
		$images = $items->getField('images');
		
		return $images[3]->getField('source');
		
	}

	function getPhotoComments($details)
	{
		
		try
		{
			
		  $response = $this->fb->get('/'.$details->photo_id.'/comments', $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('getPhotoComments', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('getPhotoComments', 'sdk', $e->getMessage());
			return false;
			
		}

		$items = $response->getGraphEdge();
		
		$comments = array();
		
		$passes_check = false;
		
		$count = 0;
		
		foreach ($items as $item):
		
			$message = $item->getField('message');
		
			$comment_id = $item->getField('id');
		
			if ($this->search_type == 'keyword'):
		
				if (preg_match("/$this->search_term/i", $message)):
		
					$passes_check = true;
		
				endif;
		
			else:
		
				$passes_check = true;
		
			endif;
		
			$comment_replies = $this->getCommentReplies($comment_id,$passed_check);
		
			if ($comment_replies)
				$passes_check = true;

			$user = $item->getField('from');
			$user_id = $user->getField('id');
			$user_name = $user->getField('name');

			$datetime = $item->getField('created_time');
			$datetime->setTimezone(new DateTimeZone($this->timezone));
			$date = $datetime->format('Y-m-d H:i:s');

			$comments[$details->photo_id][] = (object)array(
				'datetime' => $date,
				'comment_id' => $comment_id,
				'user_id' => $user_id,
				'name' => $user_name,
				'message' => $message,
				'replies' => $comment_replies
			);
		
			$count++;
			
		endforeach;
		
		return ($passes_check) ? array_filter($comments) : false;
		
	}
	
	function getCommentReplies($comment_id,$parent_check)
	{
		
		try
		{
			
		  $response = $this->fb->get('/'.$comment_id.'/comments', $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('getCommentReplies', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('getCommentReplies', 'sdk', $e->getMessage());
			return false;
			
		}
		
		$items = $response->getGraphEdge();
		
		$comments = array();
		
		$passes_check = ($parent_check ? true : false);
		
		foreach ($items as $item):
		
			$message = $item->getField('message');
			
			$user = $item->getField('from');
			$user_id = $user->getField('id');
			$user_name = $user->getField('name');
		
			$datetime = $item->getField('created_time');
			$datetime->setTimezone(new DateTimeZone($this->timezone));
			$date = $datetime->format('Y-m-d H:i:s');
		
			if ($this->search_type == 'keyword'):
		
				if (preg_match("/$this->search_term/i", $message)):
		
					$passes_check = true;
		
				endif;
		
			else:
		
				$passes_check = true;
		
			endif;

			$comments[] = (object)array(
				'datetime' => $date,
				'comment_id' => $item->getField('id'),
				'user_id' => $user_id,
				'name' => $user_name,
				'message' => $message
			);
			
		endforeach;
		
		return ($passes_check) ? $comments : false;
		
	}
	
	function compile_comments()
	{
		
		$comments = array();
		
		$this->getAlbums();
	
		$albums = $this->albums;

		$album_photos = array();

		foreach ($albums as $album):

			$this->getAlbumPhotos($album->id);

			$album_photos[] = $this->photo_ids;

			$this->photo_ids = array();

		endforeach;

		if (!empty($album_photos)):

			foreach ($album_photos as $album):

				foreach ($album as $details):

					$returned = $this->getPhotoComments($details);

					if (!empty($returned))
						return $returned;

				endforeach;

			endforeach;

		endif;
		
		return $comments;
		
	}
	
	function getMembers($group_id)
	{
		
		
		try
		{
			
		  $response = $this->fb->get('/'.$group_id.'/members/', $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('getMembers', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('getMembers', 'sdk', $e->getMessage());
			return false;
			
		}

		$items = $response->getGraphEdge();
		
		return $items;
		
	}
	
	function get_user_profile_id()
	{
		
		try
		{
			
		  $response = $this->fb->get('/me?fields=id', $this->access_token);
			
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('get_user_profile_id', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('get_user_profile_id', 'sdk', $e->getMessage());
			return false;
			
		}

		$user = $response->getGraphUser();
		
		return $user['id'];
		
	}
	
	function getUser()
	{
		
		
		try
		{
			
		  $response = $this->fb->get('/'.$this->profile_id, $this->access_token);
		  
		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{
			
			$this->capture_error('getUser', 'response', $e->getMessage());
			return false;
			
		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{
			
			$this->capture_error('getUser', 'sdk', $e->getMessage());
			return false;
			
		}


		$items = $response->getGraphNode();
		
		return $items;
		
	}

	function build_comments_table()
	{
		
		global $wpdb;
		
		$current_user = wp_get_current_user();

		$this->group_id = $_GET['group_id'];

		$this->search_term = $_GET['keyword'];

		$this->item_author = $_GET['author'];

		$this->search_type = $_GET['type'];
		
		$group = $this->getGroup();
		
		$wpdb->insert(
			$wpdb->im_fb_searches,
			array(
				'user_id' => $current_user->ID,
				'group_name' => $group->name,
				'group_id' => $this->group_id,
				'search_term' => $this->search_term,
				'author' => $this->item_author,
				'type' => $this->search_type
			),
			array(
				'%d',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s'
			)
		);
		
		$search_record = $wpdb->insert_id;

		$this->getAlbums();
		
		$albums = $this->albums;

		if (!$albums)
			header("HTTP/1.0 404 No albums found.  Please select another group.");

		$album_photos = array();

		foreach ($albums as $album):

			$this->album_name = $album->name;

			$this->getAlbumPhotos($album->id);

			if (!empty($this->photo_ids))
				$album_photos[] = $this->photo_ids;

			$this->photo_ids = array();

		endforeach;
		
		if (empty($album_photos))
			header("HTTP/1.0 404 No photos present within albums.");

		?>

		<table id="photo-comments" cellpadding="0" cellspacing="0" border="0">
			<thead>
				<tr> 
					<th>Image</th>  
					<th>Comments</th> 
					<th>Album</th>
					<th>Posted By</th>
				</tr> 
			</thead> 
			<tfoot>
				<tr> 
					<th>Image</th> 
					<th>Comments</th>  
					<th>Album</th>
					<th>Posted By</th>
				</tr> 
			</tfoot>
			<tbody> 

		<?php

		if (!empty($album_photos)):

			foreach ($album_photos as $album):

				foreach ($album as $details):

					$photo_comments = $this->getPhotoComments($details);

					if ($photo_comments):
		
						$wpdb->update(
							$wpdb->im_products,
							array(
								'status' => 'onhold'
							),
							array(
								'sku' => $details->product_id
							),
							array(
								'%s'
							),
							array(
								'%d'
							)
						);
		
						$wpdb->insert(
							$wpdb->im_fb_search_items,
							array(
								'search_id' => $search_record,
								'item_id' => $details->product_id,
								'album' => $details->album_name,
								'post_by' => $details->name,
								'fb_link' => $details->link
							),
							array(
								'%d',
								'%d',
								'%s',
								'%s',
								'%s'
							)
						);

						$search_item = $wpdb->insert_id;

						?>
						<tr>
							<td><a href="<?php echo $details->link; ?>" target="_blank"><img src="<?php echo $details->picture; ?>" /></a></td>
							<td>
								<ul>

								<?php

								foreach ($photo_comments as $photo_id => $comments):

									foreach ($comments as $comment):
		
										$wpdb->insert(
											$wpdb->im_fb_search_comments,
											array(
												'datetime' => $comment->datetime,
												'search_item' => $search_item,
												'commentor_id' => $comment->user_id,
												'commentor' => $comment->name,
												'message' => $comment->message
											),
											array(
												'%s',
												'%d',
												'%d',
												'%s',
												'%s'
											)
										);

										$parent_id = $wpdb->insert_id;

										?>

										<li>

											<div class="comment">
												<strong><?php echo $comment->name; ?></strong><br>
												<em><?php echo date('Y-m-d h:i:s a',strtotime($comment->datetime)); ?></em><br>
												<?php echo $comment->message; ?>
											</div>

											<?php if ($comment->replies): ?>

											<ul>

											<?php
											
											foreach ($comment->replies as $reply):
												
												$wpdb->insert(
													$wpdb->im_fb_search_replies,
													array(
														'datetime' => $reply->datetime,
														'parent_id' => $parent_id,
														'commentor_id' => $reply->user_id,
														'commentor' => $reply->name,
														'message' => $reply->message
													),
													array(
														'%s',
														'%d',
														'%d',
														'%s',
														'%s',
													)
												);

												?>

												<li>

													<div class="comment">
														<strong><?php echo $reply->name; ?></strong><br>
														<em><?php echo date('Y-m-d h:i:s a',strtotime($reply->datetime)); ?></em><br>
														<?php echo $reply->message; ?>
													</div>

												</li>

											<?php endforeach; ?>

											</ul>

											<?php endif; ?>

										</li>

										<?php

									endforeach;

								endforeach;

								?>
								</ul>
							</td>  
							<td><?php echo $details->album_name; ?></td>
							<td><?php echo $details->name; ?></td>
						</tr>


						<?php

					endif;


				endforeach;

			endforeach;

		endif;

		?>

		</table>

		<?php

		wp_die();

	}
	
	function redirect_facebook_page()
	{

		if (is_page('facebook')):

			$current_user = wp_get_current_user();

			if (get_user_meta($current_user->ID,'_fb_token',true)):

				wp_redirect(get_permalink(1185), 301 ); 
				exit;

			endif;

		endif;

	}
	
	function getSales()
	{
		
		global $wpdb;
		
		
		$sales = $wpdb->get_results("SELECT * FROM $wpdb->im_fb_sales ORDER BY created DESC");
		
		if ($sales):
		
		
		else:
		
		
		endif;
		
		
	}
	
	function getSearchGroupFeed($timeframe = false)
	{
		
		try
		{
			
			if ($timeframe !== false):
			
				$since = trim(strtotime($timeframe));

				$search_param = 'since='.$since;

		  		$response = $this->fb->get('/'.$this->group_id.'/feed/?'.$search_param, $this->access_token);
			
			else:

		  		$response = $this->fb->get('/'.$this->group_id.'/feed/', $this->access_token);
			
			endif;

		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{

			$this->capture_error('getFeed', 'response', $e->getMessage());
			return false;

		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{

			$this->capture_error('getFeed', 'sdk', $e->getMessage());
			return false;

		}

		$items = $response->getGraphEdge();
		
		//print_r($items);
		
		$found_posts = array();
		
		foreach ($items as $item):
		
			$post_id = $item->getField('id');
		
			$message = $item->getField('message');
		
			if (stripos($this->search_term,'#') !== false):
		
				if (preg_match("/$this->search_term/g",$message)):

					$found_posts[] = $this->getPostComments($post_id);

				endif;
		
			else:

				if (stripos(strtolower($message), $this->search_term) !== false):

					$found_posts[] = $this->getPostComments($post_id);

				endif;
		
			endif;
		
		endforeach;

		return (!empty($found_posts)) ? $found_posts : false;

	}
	
	function getPostComments($post_id,$after = '')
	{
		
		try
		{
			
			if ($after != '')
		  		$response = $this->fb->get('/'.$post_id.'/comments?limit=100&after='.$after, $this->access_token);
			else
		  		$response = $this->fb->get('/'.$post_id.'/comments?limit=100', $this->access_token);

		}
		catch(Facebook\Exceptions\FacebookResponseException $e)
		{

			$this->capture_error('getFeed', 'response', $e->getMessage());
			return false;

		}
		catch(Facebook\Exceptions\FacebookSDKException $e)
		{

			$this->capture_error('getFeed', 'sdk', $e->getMessage());
			return false;

		}

		$items = $response->getGraphEdge();
		
		$metaData = $items->getMetaData();
		
		if (isset($metaData['paging']))
			$after = $metaData['paging']['cursors']['after'];
		else
			$after = false;
		
		foreach ($items as $item):
		
			$from = $item->getField('from');
			$from_id = $from->getField('id');
			$from_name = $from->getField('name');
		
			$message = $item->getField('message');
		
			$this->comments[] = (object)array(
				'name' => $from_name,
				'message' => $message
			);
		
		
		endforeach;
		
		if ($after)
			return $this->getPostComments($post_id,$after);
		
	}
	
	function add_sale()
	{
		
		global $wpdb;
		
		$current_user = wp_get_current_user();
		
		$start = (isset($_POST['sale-start']) && $_POST['sale-start'] != '' ? date('Y-m-d h:i:s',strtotime($_POST['sale-start'])) : date('Y-m-d h:i:s',strtotime('now')));
		$end = (isset($_POST['sale-end']) && $_POST['sale-end'] != '' ? date('Y-m-d h:i:s',strtotime($_POST['sale-end'])) : '0000-00-00 00:00:00');
		
		$insert = $wpdb->insert(
			$wpdb->im_fb_sales,
			array(
				'user_id' => $current_user->ID,
				'name' => $_POST['sale-name'],
				'type' => $_POST['type'],
				'group_id' => $_POST['sale-group'],
				'start_date' => $start,
				'end_date' => $end,
				'schedule' => (isset($_POST['sale-schedule']) ? 'true' : 'false'),
				'categories' => json_encode(array_filter($_POST['categories']))
			),
			array(
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s'
			)
		);
		
		if ($insert)
			die(json_encode(array('error' => false,'record_id' => $wpdb->insert_id)));
		else
			die(json_encode(array('error' => true,'message' => 'Could not insert sale.  Please try again.  If the problem persists please contact the admin')));
		
	}
	
	function process_batch($choosen)
	{
		
		global $wpdb, $im_settings;
		
		if (empty($choosen))
			return false;

		$current_user = wp_get_current_user();
		
		$categories = $wpdb->get_results("SELECT * FROM $wpdb->im_categories WHERE id IN (".implode(',',$choosen).") ORDER BY title ASC");

		$total_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->im_products WHERE cat_id IN (".implode(',',$choosen).") AND status = 'active'");

		foreach ($categories as $category):

			$products = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						$wpdb->im_products.id,
						$wpdb->im_products.title,
						$wpdb->im_products.image_id,
						$wpdb->im_products.sku,
						$wpdb->im_products.subcat_id
					FROM 
						$wpdb->im_products 
					INNER JOIN 
						$wpdb->im_subcategories 
					ON 
						$wpdb->im_products.subcat_id = $wpdb->im_subcategories.id 
					WHERE 
						$wpdb->im_products.cat_id = %d 
					AND 
						$wpdb->im_products.status = 'active'
					AND 
						$wpdb->im_products.user_id = %d
					ORDER BY 
						$wpdb->im_subcategories.order, $wpdb->im_products.title 
					ASC",
					$category->id,
					$current_user->ID
				)
			);

			if ($products):
		
				$im_settings->capture_action(
					array(
						'progress' => 0,
						'total' => 0,
						'message' => 'Created Album for '.$category->title
					)
				);

				$this->params = array(
					'name' => $category->title.' - $'.$category->price,
					'message' => '',
					'privacy' => array('value' => 'EVERYONE')
				);

				$album_id = $this->createAlbum();

				$this->album_id = $album_id;
		
				$im_settings->capture_action(
					array(
						'progress' => 0,
						'total' => 0,
						'message' => 'Batching '.count($products).' products for '.$category->title
					)
				);

				$album_batch = array();
		
				$product_count = 0;

				foreach ($products as $product):
		
					if ($product_count == 0):
		
						$image = wp_get_attachment_image_src($category->image_id, 'full');

						$album_batch[$product->subcat_id][0] = $this->fb->request('POST', '/'.$this->album_id.'/photos', [
							'url' => $image[0],
							'message' => $category->title.' Sizing Chart',
							'no_story' => false,
							'position' => 0
						]);
		
					endif;

					if ($product->image_id == 0)
						continue;

					$image = wp_get_attachment_image_src($product->image_id, 'full');

					$album_batch[$product->subcat_id][$product->id] = $this->fb->request('POST', '/'.$this->album_id.'/photos', [
						'url' => $image[0],
						'message' => $category->title.' - $'.$category->price."\n".'ID: '.$product->sku,
						'no_story' => false,
						'position' => $product_count+5
					]);
		
					$product_count++;

				endforeach;
		
				$im_settings->capture_action(
					array(
						'progress' => 0,
						'total' => 0,
						'message' => 'Starting upload for '.$category->title
					)
				);
		
				$errors = array();

				$upload_count = 1;

				foreach ($album_batch as $subcat):
		
					if (count($subcat) > 50)
						$album_chunks = array_chunk($subcat, 50, true);
					else
						$album_chunks = array(0 => $subcat);
		
					foreach ($album_chunks as $chunk):
					
						try {

						  $responses = $this->fb->sendBatchRequest($chunk);

						}
						catch(Facebook\Exceptions\FacebookResponseException $e)
						{
							
							$errors[] = array(
								'type' => 'response',
								'message' => $e->getMessage()
							);

							//echo 'Graph returned an error: ' . $e->getMessage();
							$this->capture_error('BatchUpload', 'response', $e->getMessage());
							continue;

						}
						catch(Facebook\Exceptions\FacebookSDKException $e)
						{
							
							$errors[] = array(
								'type' => 'sdk',
								'message' => $e->getMessage()
							);

							//echo 'Facebook SDK returned an error: ' . $e->getMessage();
							$this->capture_error('BatchUpload', 'sdk', $e->getMessage());
							continue;

						}

						foreach ($responses as $key => $response):

							if ($response->isError()):

								$e = $response->getThrownException();
		
								$errors[] = array(
									'type' => 'graph',
									'message' => $e->getMessage()
								);
		
								print_r($errors);

							else:
		
								$im_settings->capture_action(
									array(
										'progress' => $upload_count,
										'total' => $product_count,
										'message' => 'Uploading products for '.$category->title
									)
								);

								$upload_count++;

							endif;

						endforeach;

					endforeach;
		
					$im_settings->capture_action(
						array(
							'progress' => 0,
							'total' => 0,
							'message' => 'Completed upload for '.$category->title
						)
					);
		
				endforeach;

			endif;

		endforeach;
		
	}
	
	function process_sale($choosen)
	{
		
		global $wpdb, $im_settings;
		
		if (empty($choosen))
			return false;
		
		$current_user = wp_get_current_user();
		
		$categories = $wpdb->get_results("SELECT * FROM $wpdb->im_categories WHERE id IN (".implode(',',$choosen).") ORDER BY title ASC");

		$total_count = $wpdb->get_var("SELECT count(*) FROM $wpdb->im_products WHERE cat_id IN (".implode(',',$choosen).") AND status = 'active'");
		
		$im_settings->capture_action(
			array(
				'progress' => 0,
				'total' => 0,
				'message' => 'Batching upload'
			)
		);

		$upload_count = 1;

		foreach ($categories as $category):
		
			$products = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						$wpdb->im_products.title,
						$wpdb->im_products.image_id,
						$wpdb->im_products.sku
					FROM 
						$wpdb->im_products 
					INNER JOIN 
						$wpdb->im_subcategories 
					ON 
						$wpdb->im_products.subcat_id = $wpdb->im_subcategories.id 
					WHERE 
						$wpdb->im_products.cat_id = %d 
					AND 
						$wpdb->im_products.status = 'active' 
					AND 
						$wpdb->im_products.user_id = %d
					ORDER BY 
						$wpdb->im_subcategories.order, $wpdb->im_products.title 
					ASC",
					$category->id,
					$current_user->ID
				)
			);

			if ($products):
		
				$product_count = count($products);
		
				$im_settings->capture_action(
					array(
						'progress' => 0,
						'total' => 0,
						'message' => 'Created Album for '.$category->title
					)
				);

				$this->params = array(
					'name' => $category->title.' - $'.$category->price,
					'message' => '',
					'privacy' => array('value' => 'EVERYONE')
				);

				$album_id = $this->createAlbum();

				$this->album_id = $album_id;

				$image = wp_get_attachment_image_src($category->image_id, 'full');

				$this->params = array(
					'url' => $image[0],
					'message' => $category->title.' Sizing Chart',
					'no_story' => false
				);

				$response = $this->uploadPhotosToAlbum();
		
				$uploaded_products = 1;
		
				foreach ($products as $product):

					if ($product->image_id == 0)
						continue;

					$image = wp_get_attachment_image_src($product->image_id, 'full');

					$this->params = array(
						'url' => $image[0],
						'message' => $category->title.' - $'.$category->price."\n".'ID: '.$product->sku,
						'no_story' => false
					);

					$try_count = 0;

					do 
					{

						$response = $this->uploadPhotosToAlbum();

						if ($try_count == 5)
							break;

						$try_count++;

					}
					while ($response === false);

					if ($response->getField('id')):
		
						$im_settings->capture_action(
							array(
								'progress' => $uploaded_products,
								'total' => $product_count,
								'message' => 'Uploading products for '.$category->title
							)
						);
		
						$upload_count++;
		
						$uploaded_products++;
		
					endif;

				endforeach;
		
				$im_settings->capture_action(
					array(
						'progress' => 0,
						'total' => 0,
						'message' => 'Completed upload for '.$category->title
					)
				);

			endif;

		endforeach;
		
	}
	
	function process_elegant($choosen)
	{
		
		global $wpdb, $im_settings;
		
		if (empty($choosen))
			return false;
		
		$current_user = wp_get_current_user();

		$categories = $wpdb->get_results("SELECT * FROM $wpdb->im_categories WHERE id IN (".implode(',',$choosen).") AND catelogue_id = 8 ORDER BY title ASC");

		$total_count = $wpdb->get_var("SELECT count(*) FROM $wpdb->im_products WHERE cat_id IN (".implode(',',$choosen).") AND catelogue_id = 8 AND status = 'active'");
		
		$im_settings->capture_action(
			array(
				'progress' => 0,
				'total' => 0,
				'message' => 'Batching upload'
			)
		);
		
		$im_settings->capture_action(
			array(
				'progress' => 0,
				'total' => 0,
				'message' => 'Created Album for '.$category->title
			)
		);

		$this->params = array(
			'name' => 'Elegant Collection',
			'message' => '',
			'privacy' => array('value' => 'EVERYONE')
		);

		$album_id = $this->createAlbum();

		$this->album_id = $album_id;

		$image = wp_get_attachment_image_src(2284, 'full');

		$this->params = array(
			'url' => $image[0],
			'message' => 'Elegant Collection',
			'no_story' => false
		);

		$response = $this->uploadPhotosToAlbum();

		$upload_count = 1;

		foreach ($categories as $category):
		
			$products = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						$wpdb->im_products.title,
						$wpdb->im_products.image_id,
						$wpdb->im_products.sku
					FROM 
						$wpdb->im_products 
					INNER JOIN 
						$wpdb->im_subcategories 
					ON 
						$wpdb->im_products.subcat_id = $wpdb->im_subcategories.id 
					WHERE 
						$wpdb->im_products.cat_id = %d 
					AND 
						$wpdb->im_products.status = 'active' 
					AND 
						$wpdb->im_products.user_id = %d
					ORDER BY 
						$wpdb->im_subcategories.order, $wpdb->im_products.title 
					ASC",
					$category->id,
					$current_user->ID
				)
			);

			if ($products):
		
				foreach ($products as $product):

					if ($product->image_id == 0)
						continue;

					$image = wp_get_attachment_image_src($product->image_id, 'full');

					$this->params = array(
						'url' => $image[0],
						'message' => $category->title.' - $'.$category->price."\n".'ID: '.$product->sku,
						'no_story' => false
					);

					$try_count = 0;

					do 
					{

						$response = $this->uploadPhotosToAlbum();

						if ($try_count == 5)
							break;

						$try_count++;

					}
					while ($response === false);

					if ($response->getField('id')):
		
						$im_settings->capture_action(
							array(
								'progress' => $upload_count,
								'total' => $total_count,
								'message' => 'Uploading products for '.$category->title
							)
						);
		
						$upload_count++;
		
					endif;

				endforeach;
		
				$im_settings->capture_action(
					array(
						'progress' => 0,
						'total' => 0,
						'message' => 'Completed upload for '.$category->title
					)
				);

			endif;

		endforeach;
		
	}
	
	function get_fb_comments()
	{
		
		$this->group_id = $_REQUEST['search-group'];

		$this->search_term = $_REQUEST['search-term'];

		$feed = $this->getSearchGroupFeed($_REQUEST['search-timeframe']);
		
		if (!$feed)
			die(json_encode(array('error' => true)));

		$random_comment_keys = array_rand($this->comments, 5);

		$count = 1;

		$html = '
		<table cellspacing="0" id="comment-search" class="details" border="0" cellpadding="0">             
			<thead>
				<tr>  
					<th>Name</th>
					<th>Comment</th>
				</tr> 
			</thead> 
			<tfoot>
				<tr> 
					<th>Name</th>
					<th>Comment</th>
				</tr> 
			</tfoot>
			<tbody>';

		foreach ($random_comment_keys as $key):
		
			$alt = ($count%2 == 0) ? ' class="odd"' : '';

			$html .= '
			<tr>
				<td>'.$this->comments[$key]->name.'</td>
				<td>'.$this->comments[$key]->message.'</td>
			</tr>';

			$count++;

		endforeach;
		
		$html .= '
			</tbody> 

		</table>';
		
		die(json_encode(array('error' => false, 'html' => $html)));
		
	}
	
	function process_partner_batch($albums)
	{
		
		global $wpdb, $im_settings;
		
		if (empty($albums))
			return false;

		$current_user = wp_get_current_user();

		foreach ($albums as $album):

			$products = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT 
						$wpdb->im_products.id,
						$wpdb->im_products.title,
						$wpdb->im_products.image_id,
						$wpdb->im_products.sku,
						$wpdb->im_products.subcat_id,
						(SELECT $wpdb->im_categories.title FROM $wpdb->im_categories WHERE $wpdb->im_products.cat_id = $wpdb->im_categories.id) as category,
						(SELECT $wpdb->im_categories.price FROM $wpdb->im_categories WHERE $wpdb->im_products.cat_id = $wpdb->im_categories.id) as cat_price,
						(SELECT $wpdb->im_subcategories.title FROM $wpdb->im_subcategories WHERE $wpdb->im_products.subcat_id = $wpdb->im_subcategories.id) as subcategory
					FROM 
						$wpdb->im_products 
					INNER JOIN 
						$wpdb->im_subcategories 
					ON 
						$wpdb->im_products.subcat_id = $wpdb->im_subcategories.id 
					WHERE 
						$wpdb->im_products.cat_id = %d 
					AND 
						$wpdb->im_products.subcat_id = %d 
					AND 
						$wpdb->im_products.status = 'active'
					AND 
						$wpdb->im_products.user_id = %d
					ORDER BY 
						$wpdb->im_subcategories.order, $wpdb->im_products.title 
					ASC",
					$album->category_id,
					$album->subcategory_id,
					$current_user->ID
				)
			);

			if ($products):

				$product_count = count($products);

				$this->album_id = $album->album_id;
		
				$uploaded_products = 1;
		
				foreach ($products as $product):

					if ($product->image_id == 0)
						continue;

					$image = wp_get_attachment_image_src($product->image_id, 'full');

					$this->params = array(
						'url' => $image[0],
						'message' => $product->category.' - '.$product->subcategory."\n".'$'.$product->cat_price."\n".'ID: '.$product->sku,
						'no_story' => false
					);

					$try_count = 0;

					do 
					{

						$response = $this->uploadPhotosToAlbum();

						if ($try_count == 5)
							break;

						$try_count++;

					}
					while ($response === false);

					if ($response->getField('id')):
		
						$im_settings->capture_action(
							array(
								'progress' => $uploaded_products,
								'total' => $product_count,
								'message' => 'Uploading products for '.$product->category.' - '.$product->subcategory
							)
						);
		
						$upload_count++;
		
						$uploaded_products++;
		
					endif;

				endforeach;

				$category = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->im_categories WHERE id = %d ORDER BY title ASC",$album->category_id));

				$subcategory = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->im_subcategories WHERE id = %d ORDER BY title ASC",$album->subcategory_id));
		
				$im_settings->capture_action(
					array(
						'progress' => 0,
						'total' => 0,
						'message' => 'Completed upload for '.$category->title.' - '.$subcategory->title
					)
				);

			endif;

		endforeach;
		
	}
	
	function process_upload()
	{
		
		global $wpdb, $im_settings;
		
		$current_user = wp_get_current_user();
		
		$sale = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->im_fb_sales WHERE id = %d",$_GET['id']));
		
		$time_start = microtime(true);

		$this->group_id = $sale->group_id;

		$this->set_access_token();

		switch ($sale->type):
		
			case 'vip':
		
				$is_partner = false;

				$choosen_categories = json_decode($sale->categories);

				$leggings = array();
				$outfits = array();
				$other_products = array();
				$elegant = array();

				foreach ($choosen_categories as $category):

					$catelogue_id = $wpdb->get_var($wpdb->prepare("SELECT catelogue_id FROM $wpdb->im_categories WHERE id = %d",$category));

					switch ($catelogue_id):

						case 4:
							$leggings[] = $category;
							break;

						case 8:
							$elegant[] = $category;
							break;

						case 9:
							$outfits[] = $category;
							break;

						default:
							$other_products[] = $category;
							break;

					endswitch;

				endforeach;

				//leggings
				$this->process_batch($leggings);

				//elegant
				$this->process_elegant($elegant);

				//outfits
				$this->process_batch($outfits);

				//Everything but leggings and elegant
				$this->process_sale($other_products);
				break;

			case 'partner':
		
				$is_partner = true;
		
				$choosen_categories = json_decode($sale->categories);

				$albums = array();

				foreach ($choosen_categories as $category):
		
					list($ablum_id, $category_id, $subcategory_id) = explode('|',$category);
		
					$albums[] = (object)array(
						'album_id' => $ablum_id,
						'category_id' => $category_id,
						'subcategory_id' => $subcategory_id
					);

				endforeach;

				$this->process_partner_batch($albums);
		
				break;
		
		endswitch;

		$time_end = microtime(true);

		//dividing with 60 will give the execution time in minutes other wise seconds
		$execution_time = $time_end - $time_start;

		$im_settings->capture_action(
			array(
				'complete' => true,
				'progress' => 1,
				'total' => 1,
				'message' => '<b>Total Execution Time:</b> '.date('H:i:s',$execution_time)
			)
		);
		exit;

	}
	
	function build_album_mapping()
	{
		
		global $wpdb;
		
		$current_user = wp_get_current_user();

		$this->group_id = $_GET['group_id'];

		$this->getAlbums();
		
		if (empty($this->albums))
			die(json_encode(array('error' => true, 'message' =>'empty')));
		
		usort($this->albums, function($a, $b) {
			return strcmp($a->name,$b->name);
		});

		$inventory = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					*,
					(SELECT $wpdb->im_catelogues.title FROM $wpdb->im_catelogues WHERE $wpdb->im_products.catelogue_id = $wpdb->im_catelogues.id) as catelogue,
					(SELECT $wpdb->im_categories.title FROM $wpdb->im_categories WHERE $wpdb->im_products.cat_id = $wpdb->im_categories.id) as category,
					(SELECT $wpdb->im_subcategories.title FROM $wpdb->im_subcategories WHERE $wpdb->im_products.subcat_id = $wpdb->im_subcategories.id) as subcategory,
					(SELECT $wpdb->im_subcategories.order FROM $wpdb->im_subcategories WHERE $wpdb->im_products.subcat_id = $wpdb->im_subcategories.id) as subcat_order
				FROM $wpdb->im_products 
				WHERE 
					$wpdb->im_products.status IN ('active') 
				AND 
					$wpdb->im_products.user_id = %d 
				GROUP BY cat_id, subcat_id 
				ORDER BY category, subcat_order ASC",
				$current_user->ID
			)
		);
		
		$subcategory_replace = array(
			'XXXL',
			'XXL',
			'One Size',
			'Tall & Curvy'
		);
		
		$subcategory_with = array(
			'3XL',
			'2XL',
			'OS',
			'TC'
		);

		$html = '
		<table id="fb-sale-mapping" cellpadding="0" cellspacing="0" border="0">
			<thead>
				<tr> 
					<th>Sale Folder</th>  
					<th>Current Product</th>
				</tr>
			</thead>
			<tbody>';

		foreach ($inventory as $product):

			$html .= '
			<tr>
				<td class="fb-album-select">
				
					<select id="'.strtolower(sanitize_title($product->category.' - '.$product->subcategory)).'" name="categories[]">

						<option value="">Select Album for Product</option>';

						foreach ($this->albums as $album):

							$html .= '<option value="'.$album->id.'|'.$product->cat_id.'|'.$product->subcat_id.'" '.(preg_match("/\b$product->category\b/i", $album->name) && (preg_match("/\$product->subcategory\b/i", $album->name) || preg_match("/\b".str_replace($subcategory_replace,$subcategory_with, $product->subcategory)."\b/i", $album->name)) ? 'selected="selected"' : '').'>'.$album->name.'</option>';

						endforeach;

					$html .= '
					</select>
				</td>
				<td class="fb-album-mapping">'.$product->category.' - '.$product->subcategory.'</td>
			</tr>';


		endforeach;

		$html .= '
			</tbody>
		</table>';
		
		die(json_encode(array('error' => false, 'html' => $html)));
		
	}
	
}
			  
global $im_facebook;

$im_facebook = new IM_Facebook();

?>