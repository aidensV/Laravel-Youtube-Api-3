<?php
/**
 * Created by PhpStorm.
 * User: AD
 * Date: 11/18/2017
 * Time: 10:12 PM
 */
return [
	'auth_config_path' => env('GOOGLE_CLIENT_CONFIG_PATH', null),
	'service_account_path' => env('GOOGLE_CLIENT_SERVICE_ACCOUNT_PATH', null),
	'drive_folder_id' => env('DRIVE_FOLDER_ID', null),
	/**
	 * Scopes.
	 */
	'scopes' => [
		'https://www.googleapis.com/auth/youtube',
		'https://www.googleapis.com/auth/youtube.upload',
		'https://www.googleapis.com/auth/youtube.readonly',
		'https://www.googleapis.com/auth/drive',
		'https://www.googleapis.com/auth/youtube.force-ssl',
		'https://www.googleapis.com/auth/youtubepartner',
	],
	/**
	 * Route URI's
	 */
	'routes' => [
		/**
		 * Determine if the Routes should be disabled.
		 * Note: We recommend this to be set to "false" immediately after authentication.
		 */
		'enabled' => env('YOUTUBE_AUTH', false),
		/**
		 * The prefix for the below URI's
		 */
		'prefix' => 'youtube',
		/**
		 * Redirect URI
		 */
		'redirect_uri' => 'callback',
		/**
		 * The autentication URI
		 */
		'authentication_uri' => 'auth',
		/**
		 * The redirect back URI
		 */
		'redirect_back_uri' => '/',
	],
];