<?php

/**
 * Created by PhpStorm.
 * User: AD
 * Date: 11/18/2017
 * Time: 10:27 PM
 */

namespace aiden\Youtube;

use Carbon\Carbon;
use Exception;
use Google_Client;
use Google_Exception;
use Google_Service_Drive;
use Google_Service_Exception;
use Google_Service_YouTube;
use Illuminate\Support\Facades\DB;

class YoutubeAPI {
	/**
	 * Application Container
	 *
	 * @var Application
	 */
	private $app;
	/**
	 * Google Client
	 *
	 * @var \Google_Client
	 */
	protected $client;
	/**
	 * Google Drive Service
	 *
	 * @var \Google_Service_Drive
	 */
	protected $drive;
	/**
	 * Google YouTube Service
	 *
	 * @var \Google_Service_YouTube
	 */
	protected $youtube;
	/**
	 * Video ID
	 *
	 * @var string
	 */
	private $videoId;
	/**
	 * Video Snippet
	 *
	 * @var array
	 */
	private $snippet;
	/**
	 * Thumbnail URL
	 *
	 * @var string
	 */
	private $thumbnailUrl;
	/**
	 * Constructor
	 *
	 * @param \Google_Client $client
	 */
	public function __construct($app, Google_Client $client) {
		$this->app = $app;
		$this->client = $this->setup($client);
		$this->youtube = new \Google_Service_YouTube($this->client);
		$this->drive = new \Google_Service_Drive($this->client);
		if ($accessToken = $this->getLatestAccessTokenFromDB()) {
			$this->client->setAccessToken($accessToken);
		}
	}
	/**
	 * Upload file to Google Drive
	 *
	 * @param string $path
	 * @param array $data
	 */
	public function uploadDrive($path, $data) {
		if (!file_exists($path)) {
			throw new Exception('File does not exist at path: "' . $path . '". Provide a full path to the file before attempting to upload.');
		}
		$this->handleAccessToken();
		try {
			$drivePath = [
				'name' => $data['name']
			];
			
			$folderId = $this->app->config->get('youtubeAPIConfig.drive_folder_id');
			if ($folderId) {
				$drivePath = [
					'name' => $data['name'],
					'parents' => [$folderId]
				];
			}

			$fileMetadata = new \Google_Service_Drive_DriveFile($drivePath);
			$content = file_get_contents($path);
			$uploadType = 'multipart';
			if(isset($data['uploadType'])){
				$uploadType = $data['uploadType'];
			}
			$file = $this->drive->files->create($fileMetadata, array(
				'data' => $content,
				'mimeType' => $data['mimeType'],
				'uploadType' => $uploadType,
				'fields' => 'id',
			));

			$userPermission = new \Google_Service_Drive_Permission(array('type' => 'anyone', 'role' => 'reader'));
			$this->drive->permissions->create($file->id, $userPermission, array('fields' => 'id'));
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
		return $file;
	}

	/**
	 * Upload file to Google Drive
	 *
	 * @param string $path
	 * @param array $data
	 */
	public function uploadBigFileDrive($path, $data) {
		if (!file_exists($path)) {
			throw new Exception('File does not exist at path: "' . $path . '". Provide a full path to the file before attempting to upload.');
		}
		$this->handleAccessToken();
		try {
			$fileMetadata = new \Google_Service_Drive_DriveFile(array('name' => $data['name']));
			$content = file_get_contents($path);
			$uploadType = 'resumable';
			if(isset($data['uploadType'])){
				$uploadType = $data['uploadType'];
			}

			// Set the Chunk Size
			$chunkSize = 1 * 1024 * 1024;
			// Set the defer to true
			$this->client->setDefer(true);

			$file = $this->drive->files->create($fileMetadata, array(
				'data' => $content,
				'mimeType' => $data['mimeType'],
				'uploadType' => $uploadType,
				'fields' => 'id',
			));

			$media = new \Google_Http_MediaFileUpload(
				$this->client,
				$file,
				'/*',
				null,
				true,
				$chunkSize
			);
			$media->setFileSize(filesize($path));
			// Read the file and upload in chunks
			$status = false;
			$handle = fopen($path, "rb");
			while (!$status && !feof($handle)) {
				$chunk = fread($handle, $chunkSize);
				$status = $media->nextChunk($chunk);
			}
			fclose($handle);
			$this->client->setDefer(false);
			
			$userPermission = new \Google_Service_Drive_Permission(array('type' => 'anyone', 'role' => 'reader'));
			$this->drive->permissions->create($file->id, $userPermission, array('fields' => 'id'));
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
		return $file;
	}

	/**
	 * Delete file from Google Drive
	 *
	 * @param string $fileId
	 */
	public function deleteDrive($fileId) {
		$this->handleAccessToken();
		try {
			return $this->drive->files->delete($fileId);
		} catch (Exception $e) {
			throw new Exception($e->getMessage());
		}
		return false;
	}

	/**
	 * Upload the video to YouTube
	 *
	 * @param  string $path
	 * @param  array  $data
	 * @param  string $privacyStatus
	 * @return string
	 */
	public function upload($path, array $data = [], $privacyStatus = 'unlisted') {
		if (!file_exists($path)) {
			throw new Exception('Video file does not exist at path: "' . $path . '". Provide a full path to the file before attempting to upload.');
		}
		$this->handleAccessToken();
		try {
			// Setup the Snippet
			$snippet = new \Google_Service_YouTube_VideoSnippet();
			if (array_key_exists('title', $data)) {
				$snippet->setTitle($data['title']);
			}

			if (array_key_exists('description', $data)) {
				$snippet->setDescription($data['description']);
			}

			if (array_key_exists('tags', $data)) {
				$snippet->setTags($data['tags']);
			}

			if (array_key_exists('category_id', $data)) {
				$snippet->setCategoryId($data['category_id']);
			}

			// Set the Privacy Status
			$status = new \Google_Service_YouTube_VideoStatus();
			$status->privacyStatus = $privacyStatus;
			// Set the Snippet & Status
			$video = new \Google_Service_YouTube_Video();
			$video->setSnippet($snippet);
			$video->setStatus($status);
			// Set the Chunk Size
			$chunkSize = 1 * 1024 * 1024;
			// Set the defer to true
			$this->client->setDefer(true);
			// Build the request
			$insert = $this->youtube->videos->insert('status,snippet', $video);
			// Upload
			$media = new \Google_Http_MediaFileUpload(
				$this->client,
				$insert,
				'video/*',
				null,
				true,
				$chunkSize
			);
			// Set the Filesize
			$media->setFileSize(filesize($path));
			// Read the file and upload in chunks
			$status = false;
			$handle = fopen($path, "rb");
			while (!$status && !feof($handle)) {
				$chunk = fread($handle, $chunkSize);
				$status = $media->nextChunk($chunk);
			}
			fclose($handle);
			$this->client->setDefer(false);
			// Set ID of the Uploaded Video
			$this->videoId = $status['id'];
			// Set the Snippet from Uploaded Video
			$this->snippet = $status['snippet'];

		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
		return $status;
	}
	/**
	 * Set a Custom Thumbnail for the Upload
	 *
	 * @param  string  $imagePath
	 *
	 * @return self
	 */
	public function withThumbnail($imagePath) {
		try {
			$videoId = $this->getVideoId();
			$chunkSizeBytes = 1 * 1024 * 1024;
			$this->client->setDefer(true);
			$setRequest = $this->youtube->thumbnails->set($videoId);
			$media = new \Google_Http_MediaFileUpload(
				$this->client,
				$setRequest,
				'image/png',
				null,
				true,
				$chunkSizeBytes
			);
			$media->setFileSize(filesize($imagePath));
			$status = false;
			$handle = fopen($imagePath, "rb");
			while (!$status && !feof($handle)) {
				$chunk = fread($handle, $chunkSizeBytes);
				$status = $media->nextChunk($chunk);
			}
			fclose($handle);
			$this->client->setDefer(false);
			$this->thumbnailUrl = $status['items'][0]['default']['url'];
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
		return $this;
	}

	/**
	 * Update a Youtube video by its ID
	 * @param $id, $status
	 */
	public function updateVideo($id, array $data = [], $privacyStatus = 'unlisted') {
		$this->handleAccessToken();
		try {
			// Setup the Snippet
			$snippet = new \Google_Service_YouTube_VideoSnippet();
			if (array_key_exists('title', $data)) {
				$snippet->setTitle($data['title']);
			}

			if (array_key_exists('description', $data)) {
				$snippet->setDescription($data['description']);
			}

			if (array_key_exists('tags', $data)) {
				$snippet->setTags($data['tags']);
			}

			if (array_key_exists('category_id', $data)) {
				$snippet->setCategoryId($data['category_id']);
			}

			// Set the Privacy Status
			$status = new \Google_Service_YouTube_VideoStatus();
			$status->privacyStatus = $privacyStatus;
			// Set the Snippet & Status
			$video = new \Google_Service_YouTube_Video();
			$video->setId($id);
			if (!emptyArray($data)) {
				$video->setSnippet($snippet);
			}

			$video->setStatus($status);
			$update = $this->youtube->videos->update('status,snippet', $video);
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
		return $update;
	}

	/**
	 * Video list by id
	 * @param $id
	 *
	 */
	public function videosListById($array) {
		$this->handleAccessToken();
		try {
			$video = new \Google_Service_YouTube_Video();
			$params = array_filter($array);
			$response = $this->youtube->videos->listVideos(
				'snippet,contentDetails,statistics',
				$params
			);
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
		return $response->items;
	}

	/**
	 * Delete a YouTube video by it's ID.
	 *
	 * @param  int  $id
	 *
	 * @return bool
	 */
	public function delete($id) {
		$this->handleAccessToken();
		if (!$this->exists($id)) {
			throw new Exception('A video matching id "' . $id . '" could not be found.');
		}
		return $this->youtube->videos->delete($id);
	}
	/**
	 * Check if a YouTube video exists by it's ID.
	 *
	 * @param  int  $id
	 *
	 * @return bool
	 */
	public function exists($id) {
		$this->handleAccessToken();
		$response = $this->youtube->videos->listVideos('status', ['id' => $id]);
		if (empty($response->items)) {
			return false;
		}

		return true;
	}
	/**
	 * Return the Video ID
	 *
	 * @return string
	 */
	public function getVideoId() {
		return $this->videoId;
	}
	/**
	 * Return the snippet of the uploaded Video
	 *
	 * @return array
	 */
	public function getSnippet() {
		return $this->snippet;
	}
	/**
	 * Return the URL for the Custom Thumbnail
	 *
	 * @return string
	 */
	public function getThumbnailUrl() {
		return $this->thumbnailUrl;
	}
	/**
	 * Setup the Google Client
	 *
	 * @param \Google_Client $client
	 * @return \Google_Client $client
	 */
	private function setup(Google_Client $client) {
		if (
			!$this->app->config->get('youtubeAPIConfig.service_account_path')
		) {
			throw new Exception('A Google "service_account_path" must be configured.');
		}
		$client->setAuthConfig($this->app->config->get('youtubeAPIConfig.service_account_path'));
		$client->setScopes($this->app->config->get('youtubeAPIConfig.scopes'));
		$client->setPrompt('consent');
		$client->setAccessType('offline');
		$client->setRedirectUri(url(
			$this->app->config->get('youtubeAPIConfig.routes.prefix')
			. '/' .
			$this->app->config->get('youtubeAPIConfig.routes.redirect_uri')
		));
		return $this->client = $client;
	}
	/**
	 * Saves the access token to the database.
	 *
	 * @param  string  $accessToken
	 */
	public function saveAccessTokenToDB($accessToken) {
		// dd($accessToken);
		return DB::table('youtube_access_tokens')->insert([
			'access_token' => json_encode($accessToken),
			'created_at' => Carbon::createFromTimestamp($accessToken['created']),
		]);
	}
	/**
	 * Get the latest access token from the database.
	 *
	 * @return string
	 */
	public function getLatestAccessTokenFromDB() {
		$latest = DB::table('youtube_access_tokens')
			->latest('created_at')
			->first();
		return $latest ? (is_array($latest) ? $latest['access_token'] : $latest->access_token) : null;
	}
	/**
	 * Handle the Access Token
	 *
	 * @return void
	 */
	public function handleAccessToken() {
		if (is_null($accessToken = $this->client->getAccessToken())) {
			throw new \Exception('An access token is required.');
		}

		if ($this->client->isAccessTokenExpired()) {
			// If we have a "refresh_token"
			if (array_key_exists('refresh_token', $accessToken)) {
				// Refresh the access token
				$this->client->fetchAccessTokenWithAssertion();
				// Save the access token
				$this->saveAccessTokenToDB($this->client->getAccessToken());
			}
		}
	}
	/**
	 * Pass method calls to the Google Client.
	 *
	 * @param  string  $method
	 * @param  array   $args
	 *
	 * @return mixed
	 */
	public function __call($method, $args) {
		return call_user_func_array([$this->client, $method], $args);
	}

	/***
		     * Create a playlist
	*/
	public function createPlaylist($name, $descriptions, $privacy) {

		$this->handleAccessToken();
		try {

			// 1. Create the snippet for the playlist. Set its title and description.
			$playlistSnippet = new \Google_Service_YouTube_PlaylistSnippet();
			$playlistSnippet->setTitle($name);
			$playlistSnippet->setDescription($descriptions);

			// 2. Define the playlist's status.
			$playlistStatus = new \Google_Service_YouTube_PlaylistStatus();
			$playlistStatus->setPrivacyStatus($privacy);

			// 3. Define a playlist resource and associate the snippet and status
			// defined above with that resource.
			$youTubePlaylist = new \Google_Service_YouTube_Playlist();
			$youTubePlaylist->setSnippet($playlistSnippet);
			$youTubePlaylist->setStatus($playlistStatus);

			// 4. Call the playlists.insert method to create the playlist. The API
			// response will contain information about the new playlist.
			$playlistResponse = $this->youtube->playlists->insert('snippet,status',
				$youTubePlaylist, array());
			//$playlistId = $playlistResponse['id'];
			return $playlistResponse;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	/***
		     * Get all playlist by channel id
	*/

	public function getAllPlayList() {
		$this->handleAccessToken();
		try {
			$params = array('mine' => true, 'maxResults' => 25);
			//Array marge
			$response = $this->youtube->playlists->listPlaylists('snippet,status', $params);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	/***
		     * Check if playlist Exists
	*/

	public function isPlaylistExist() {

	}

	/***
		     * Update a playlist
	*/

	public function updatePlaylist($id, $title, $description, $privacy) {
		$this->handleAccessToken();
		try {
			// 1. Create the snippet for the playlist. Set its title and description.
			$playlistSnippet = new \Google_Service_YouTube_PlaylistSnippet();
			$playlistSnippet->setTitle($title);
			$playlistSnippet->setDescription($description);

			// 2. Define the playlist's status.
			$playlistStatus = new \Google_Service_YouTube_PlaylistStatus();
			$playlistStatus->setPrivacyStatus($privacy);

			// 3. Define a playlist resource and associate the snippet and status
			$youTubePlaylist = new \Google_Service_YouTube_Playlist();
			$youTubePlaylist->setId($id);
			$youTubePlaylist->setSnippet($playlistSnippet);
			$youTubePlaylist->setStatus($playlistStatus);

			// 4. Call the playlists.update method to update the playlist
			$playlistResponse = $this->youtube->playlists->update('snippet,status',
				$youTubePlaylist, array());
			return $playlistResponse;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	/***
		     * Delete playlist
	*/

	public function deletePlaylist($id) {
		$this->handleAccessToken();
		try {
			$playlistResponse = $this->youtube->playlists->delete($id);
			return $playlistResponse;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	/**Get playlist information **/
	public function playListInfoById($id) {
		$this->handleAccessToken();
		try {

			$params = array('id' => $id);
			//Array marge
			$response = $this->youtube->playlists->listPlaylists('snippet', $params);
			return $response['items'];
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	/**Get playlist items **/
	public function playListItemById($id) {
		$this->handleAccessToken();
		try {
			$response = $this->youtube->playlistItems->listPlaylistItems('snippet,contentDetails',
				array('maxResults' => 25, 'playlistId' => $id));
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	/**Insert video into a playlist **/
	public function insertVideoInPlaylist($videoId, $playlistId) {
		$this->handleAccessToken();
		try {
			// 5. Add a video to the playlist. First, define the resource being added
			// to the playlist by setting its video ID and kind.
			$resourceId = new \Google_Service_YouTube_ResourceId();
			$resourceId->setVideoId($videoId);
			$resourceId->setKind('youtube#video');

			// Then define a snippet for the playlist item. Set the playlist item's
			// title if you want to display a different value than the title of the
			// video being added. Add the resource ID and the playlist ID retrieved
			// in step 4 to the snippet as well.
			$playlistItemSnippet = new \Google_Service_YouTube_PlaylistItemSnippet();
			//$playlistItemSnippet->setTitle('First video in the test playlist');
			$playlistItemSnippet->setPlaylistId($playlistId);
			$playlistItemSnippet->setResourceId($resourceId);

			// Finally, create a playlistItem resource and add the snippet to the
			// resource, then call the playlistItems.insert method to add the playlist
			// item.

			$playlistItem = new \Google_Service_YouTube_PlaylistItem();
			$playlistItem->setSnippet($playlistItemSnippet);
			$playlistItemResponse = $this->youtube->playlistItems->insert(
				'snippet,contentDetails', $playlistItem, array());
			return $playlistItemResponse;

		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	/**
	 * Remove video from playlist
	 * @param $videoPlaylistId
	 */
	public function removeVideoFromPlaylist($id) {
		$this->handleAccessToken();
		try {
			$response = $this->youtube->playlistItems->delete($id);
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
		return $response;
	}
	/**
	 * Get Populer Video
	 */
	public function popularVideo() {
		$this->handleAccessToken();
		try {
			$queryParams = [
				'chart' => 'mostPopular',
				'regionCode' => 'ID',
			];

			$response = $this->youtube->videos->listVideos('snippet,contentDetails,statistics', $queryParams);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}

	}
	/**
	 * Get comment thread by video
	 * @param $id video id
	 */
	public function getCommentByVideo($id) {
		$this->handleAccessToken();
		try {
			$queryParams = [
				'videoId' => $id,
			];
			$response = $this->youtube->commentThreads->listCommentThreads('snippet,replies', $queryParams);
			return $response;
		} catch (\Google_Service_Exception $e) {
			if ($e->getCode() == 403) {
				return [];
			}
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}

	}
	/**
	 * Post comment thread by video id
	 * @param $id video id
	 */
	public function commentByVideo($videoId, $textComment) {
		$this->handleAccessToken();
		try {
			// Define the $commentThread object, which will be uploaded as the request body.
			$commentThread = new \Google_Service_YouTube_CommentThread();

			// Add 'snippet' object to the $commentThread object.
			$commentThreadSnippet = new \Google_Service_YouTube_CommentThreadSnippet();
			$comment = new \Google_Service_YouTube_Comment();
			$commentSnippet = new \Google_Service_YouTube_CommentSnippet();
			$commentSnippet->setTextOriginal($textComment);
			$comment->setSnippet($commentSnippet);
			$commentThreadSnippet->setTopLevelComment($comment);
			$commentThreadSnippet->setVideoId($videoId);
			$commentThread->setSnippet($commentThreadSnippet);

			$response = $this->youtube->commentThreads->insert('snippet', $commentThread);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	public function getListMySubscribtion() {
		$this->handleAccessToken();
		try {
			$queryParams = [
				'mine' => true,
			];

			$response = $this->youtube->subscriptions->listSubscriptions('snippet,contentDetails', $queryParams);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	public function subscribeByChannel($channelId) {
		$this->handleAccessToken();
		try {
			// Define the $subscription object, which will be uploaded as the request body.
			$subscription = new \Google_Service_YouTube_Subscription();

			// Add 'snippet' object to the $subscription object.
			$subscriptionSnippet = new \Google_Service_YouTube_SubscriptionSnippet();
			$resourceId = new \Google_Service_YouTube_ResourceId();
			$resourceId->setChannelId($channelId);
			$resourceId->setKind('youtube#channel');
			$subscriptionSnippet->setResourceId($resourceId);
			$subscription->setSnippet($subscriptionSnippet);

			$response = $this->youtube->subscriptions->insert('snippet', $subscription);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}

	}

	public function unSubscribeByChannel($subscribeId) {
		$this->handleAccessToken();
		try {
			$response = $this->youtube->subscriptions->delete($subscribeId);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	public function rateByVideoId($videoId, $rating) {
		$this->handleAccessToken();

		try {
			$response = $this->youtube->videos->rate($videoId, $rating);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	public function checkIsSubscribe($channelId) {
		$this->handleAccessToken();
		try {
			$queryParams = [
				'forChannelId' => $channelId,
				'mine' => true,
			];

			$response = $this->youtube->subscriptions->listSubscriptions('snippet,contentDetails', $queryParams);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	public function getAllInfoVideo($videoId) {
		$this->handleAccessToken();
		try {
			$queryParams = [
				'id' => $videoId,
			];

			$response = $this->youtube->videos->listVideos('snippet,contentDetails,statistics', $queryParams);
			$getChannelId = $response->items[0]->snippet->channelId;
			$rating = $this->getRating($videoId);
			$subscription = $this->checkIsSubscribe($getChannelId);
			$comment = $this->getCommentByVideo($videoId);
			$data = array('video' => $response, 'rating' => $rating, 'is_subscribe' => $subscription, 'listComment' => $comment);
			return $data;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}
	public function getRating($videoId) {
		$this->handleAccessToken();
		try {
			$response = $this->youtube->videos->getRating($videoId);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	public function getReportAbuse($lang) {
		$this->handleAccessToken();
		try {
			if ($lang == null) {
				$lang = 'id';
			}
			$queryParams = [
				'hl' => $lang,
			];

			$response = $this->youtube->videoAbuseReportReasons->listVideoAbuseReportReasons('snippet', $queryParams);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}

	public function reportAbuseVideo($reasenId, $videoId) {
		$this->handleAccessToken();
		try {

			// Define the $videoAbuseReport object, which will be uploaded as the request body.
			$videoAbuseReport = new \Google_Service_YouTube_VideoAbuseReport();
			// Add 'reasonId' string to the $videoAbuseReport object.
			$videoAbuseReport->setReasonId($reasenId);
			// Add 'videoId' string to the $videoAbuseReport object.
			$videoAbuseReport->setVideoId($videoId);
			$response = $this->youtube->videos->reportAbuse($videoAbuseReport);
			return $response;
		} catch (\Google_Service_Exception $e) {
			throw new Exception($e->getMessage());
		} catch (\Google_Exception $e) {
			throw new Exception($e->getMessage());
		}
	}
}
