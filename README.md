# Laravel 6 - YouTube Video Upload

**Please note, that this package will only work with a single YouTube account and does not support multiple accounts.**

## Installation

To install, use the following to pull the package in via Composer.

Add this to your composer.json

```
"repositories": {
	"laravel-youtube-api-3": {
		"type": "package",
		"package": {
			"name": "ad/youtube",
			"version": "1.0",
			"source": {
				"url": "https://github.com/jhemmmm/Laravel-Youtube-Api-3.git",
				"type": "git",
				"reference": "origin/master"
			},
			"autoload": {
				"psr-4": {
					"ad\\Youtube\\": "src/"
				}
			},
			"autoload-dev": {
				"psr-4": {
					"ad\\Youtube\\Tests\\": "tests/"
				}
			},
			"minimum-stability": "stable",
			"extra": {
				"laravel": {
					"providers": [
						"ad\\Youtube\\YoutubeAPIServiceProvider"
					],
					"aliases": {
						"Youtube": "ad\\Youtube\\Facades\\YoutubeAPI"
					}
				}
			}
		}
	}
},
```

And also add this to your "require" & "require-dev"
```
"require": {
	"ad/youtube": "1.0",
	"php": "^7.2",
	"illuminate/support": "^6.13",
	"nesbot/carbon": "^2.0",
	"google/apiclient": "^2.2",
},
"require-dev": {
	"orchestra/testbench": "^4.6",
	"phpunit/phpcov": "^6.0",
	"phpunit/phpunit": "~8.0"
},
```

Update your composer by using this command.
```
composer update
```

Now register the Service provider in `config/app.php`

```php
'providers' => [
    ...
    ad\Youtube\YoutubeAPIServiceProvider::class,
],
```

And also add the alias to the same file.

```php
'aliases' => [
    ...
    'YoutubeAPI'=>ad\Youtube\Facades\YoutubeAPI::class,
],
```

## Configuration

You now need to publish the `youtubeAPI.php` config and migrations.

```
php artisan vendor:publish --provider="ad\Youtube\YoutubeAPIServiceProvider"
```

Now you'll want to run `php artisan migrate` to create the `youtube_access_tokens` table which as you would imagine, will contain your access tokens once you're authenticated correctly.

### Obtaining your Credentials

If you haven't already, you'll need to create an application on [Google's Developer Console](https://console.developers.google.com/project). You then need to head into **Credentials** within the Console to create Server key.

You will be asked to enter your Authorised redirect URIs. When installing this package, the default redirect URI is `http://laravel.dev/youtube/callback`. Of course, replacing the domain (`laravel.dev`) with your applications domain name.

**You can add multiple redirect URIs, for example you may want to add the URIs for your local, staging and production servers.**

Once you are happy with everything, create the credentials and you will be provided with a **Client ID** and **Client Secret**. These now need to be added to your `.env` file.

```
GOOGLE_CLIENT_ID=YOUR_CLIENT_ID
GOOGLE_CLIENT_SECRET=YOUR_SECRET
```

### Authentication

For security reasons, the routes to authorize your channel with your Laravel application for disabled by default. You will need to enable them in your `config/youtubeAPIConfig.php` before doing the following.

Now your application is configured, we'll go through the inital authentication with Google. By default, the authorization route is `/youtube/auth`. Simply visit this URI in your application and you will be redirect to Google to authenticate your YouTube account.

Assuming you were not presented with any errors during authentication, you will be redirected back to your application root. (`/`).

### Reviewing your Token

Previously, users of this package have reported issues with their access token(s). To ensure you have the correct token, you simply need to review the `youtube_access_tokens` table you migrated earlier and review the value in the `access_token` column.

**You need to check that a `refresh_token` exists within this value. If this is correct, you're all set to begin uploading.**

You will also want to disable the routes used for authorization as they will no longer be required since you are now autheticated. The token you just reviewed, assuming as a `refresh_token` will automatically be handled. 

# Upload a Video

To upload a video, you simply need to pass the **full** path to your video you wish to upload and specify your video information.

Here's an example:

```php
$video = YoutubeAPI::upload($fullPathToVideo, [
    'title'       => 'My Awesome Video',
    'description' => 'You can also specify your video description here.',
    'tags'	      => ['foo', 'bar', 'baz'],
    'category_id' => 10
]);

return $video->getVideoId();
```

The above will return the ID of the uploaded video to YouTube. (*i.e dQw4w9WgXcQ*)

By default, video uploads are public. If you would like to change the privacy of the upload, you can do so by passing a third parameter to the upload method.

For example, the below will upload the video as `unlisted`.

```php
$video = YoutubeAPI::upload($fullPathToVideo, $params, 'unlisted');
```

### Custom Thumbnail

If you would like to set a custom thumbnail for for upload, you can use the `withThumbnail()` method via chaining.

```php
$fullpathToImage = storage_path('app/public/thumbnail.jpg');

$video = YoutubeAPI::upload($fullPathToVideo, $params)->withThumbnail($fullpathToImage);

return $youtube->getThumbnailUrl();
```

**Please note, the maxiumum filesize for the thumbnail is 2MB**. Setting a thumbnail will not work if you attempt to use a thumbnail that exceeds this size.

# Deleting a Video

If you would like to delete a video, which of course is uploaded to your authorized channel, you will also have the ability to delete it:

```php
YoutubeAPI::delete($videoId);
```

When deleting a video, it will check if exists before attempting to delete.


# Create playlist

```php
YoutubeAPI::createPlaylist($playlistName,$descriptions,$privacy);
```

# Get all playlist by channel 

```php
YoutubeAPI::getAllPlayList();
```


# Get all playlist info by id 

```php
YoutubeAPI::playListInfoById($playlistId);
```


# Get playlist items

```php
YoutubeAPI::playListItemById($playlistId);
```
# Insert video into playlist

```php
YoutubeAPI::insertVideoInPlaylist($videoId,$playlistId);
```

# Remove video from playlist

```php
YoutubeAPI::removeVideoFromPlaylist($videoPlaylistId);
```


# Update playlist


```php
YoutubeAPI::updatePlaylist($id,$title,$description);
```
# Delete playlist

```php
YoutubeAPI::deletePlaylist($id);
```

I have also added a google drive support.

# Upload File to Google Drive (your file will be automatically shared to public

```php
YoutubeAPI::uploadDrive($path, $data = array('name' => 'foo', 'mimeType' => 'bar'));
```

# Delete file

```php
YoutubeAPI::deleteDrive($fileId);
```

