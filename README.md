# Slack MediaWiki

This is a extension for [MediaWiki](https://www.mediawiki.org/wiki/MediaWiki) that sends notifications of actions in your wiki – like editing, adding, or removing a page – into a [Slack](https://slack.com/) channel.

> Looking for extension that can send notifications to [HipChat](https://github.com/kulttuuri/hipchat_mediawiki) or [Discord](https://github.com/kulttuuri/discord_mediawiki)?

## Supported MediaWiki operations to send notifications

* Article is added, removed, moved or edited.
* Article protection settings are changed.
* New user is added.
* User is blocked.
* File is uploaded.
* ... and each notification can be individually enabled or disabled :)

## Requirements

* MediaWiki 1.26+

## How to install

1) Create a new Slack Incoming Webhook. When setting up the webhook, define channel where you want the notifications to go into. You can setup a new webhook on [this page](https://slack.com/services/new/incoming-webhook).

2) After setting up the Webhook you will get a Webhook URL. Copy that URL as you will need it in step 4.

3) Download latest release of this extension into your extensions directory.

4) Add settings listed below in your `localSettings.php`. Note that it is mandatory to set these settings for this extension to work:

```php
wfLoadExtension("SlackNotifications");
// Required. Your Slack incoming webhook URL. Read more from here: https://api.slack.com/incoming-webhooks
$wgSlackIncomingWebhookUrl = "";
```

5) Enjoy the notifications in your Slack channel!
	
## Additional options

These options can be set after including your plugin in your localSettings.php file.

### Customize the channel where notifications gets sent to

By default, when you create an incoming Slack webhook, you'll define which channel notifications go into. You can also override this in MediaWiki by setting the parameter below. Remember to also include # before your channel name.

```php
// What channel the webhook posts to
$wgSlackRoomName = "";
```

### Set the webhook name

The name of your wiki is used as the name the webook posts with. You can change this behaviour.

```php
// What name the webhook sends as
$wgSlackFromName = $wgSiteName;
```

### Set the webhook avatar

You also define an avatar for the webhook to post with when it's created, and you can also customize it with the setting below. Any valid Slack emoji can be used, including custom ones. The name must be surrounded by colons `:`.

```php
// What avatar the webhook uses for posts
$wgSlackEmoji = "";
```

### Remove additional links from user and article pages

By default user and article links in the nofication message will get additional links to block user, view article history, etc. You can disable either one of those by setting settings below to false.

```php
// If this is true, pages will get additional links in the notification message (edit | delete | history).
$wgSlackIncludePageUrls = true;
// If this is true, users will get additional links in the notification message (block | groups | talk | contribs).
$wgSlackIncludeUserUrls = true;
// If this is true, all minor edits made to articles will not be submitted to Slack.
$wgSlackIgnoreMinorEdits = false;
```

### Disable new user extra information

By default we show full name, email and IP address of newly created user in the notification. You can individually disable each of these using the settings below. This is helpful for example in situation where you do not want to expose this information for users in your Slack channel.

```php
// If this is true, newly created user email address is added to notification.
$wgSlackShowNewUserEmail = true;
// If this is true, newly created user full name is added to notification.
$wgSlackShowNewUserFullName = true;
// If this is true, newly created user IP address is added to notification.
$wgSlackShowNewUserIP = true;
```
### Show edit size

By default we show size of the edit. You can hide this information with the setting below.

```php
$wgSlackIncludeDiffSize = false;
```

### Disable notifications from certain user roles

By default notifications from all users will be sent to your Slack channel. If you wish to exclude users in certain group to not send notification of any actions, you can set the group with the setting below. Then create the group if needed, and add users to it.

```php
// If this is set, actions by users with this permission won't cause alerts
$wgExcludedPermission = "";
```

### Disable notifications from certain pages / namespaces

You can exclude notifications from certain pages by adding them into this array. Note: this is a simple substring prefix match that targets all pages. In the example below, all pages in the **User** namespace will be excluded, but also any pages whose names start with "User:".

```php
// Actions (add, edit, modify) won't be notified to Slack room from articles starting with these names
$wgSlackExcludeNotificationsFrom = ["User:", "Weirdgroup"];
```

### Actions to notify of

MediaWiki actions that will be sent notifications of into Slack. Set desired options to false to disable notifications of those actions.

```php
// New user added into MediaWiki
$wgSlackNotificationNewUser = true;
// User or IP blocked in MediaWiki
$wgSlackNotificationBlockedUser = true;
// Article added to MediaWiki
$wgSlackNotificationAddedArticle = true;
// Article removed from MediaWiki
$wgSlackNotificationRemovedArticle = true;
// Article moved under new title in MediaWiki
$wgSlackNotificationMovedArticle = true;
// Article edited in MediaWiki
$wgSlackNotificationEditedArticle = true;
// File uploaded
$wgSlackNotificationFileUpload = true;
// Article protection settings changed
$wgSlackNotificationProtectedArticle = true;
```

## Setting proxy

To add proxy for requests, you can use the normal MediaWiki way of setting proxy, as described [here](https://www.mediawiki.org/wiki/Manual:$wgHTTPProxy). Basically this means that you just need to set `$wgHTTPProxy` parameter in your `localSettings.php` file to point to your proxy.

## Contributors

Based on code by [@kulttuuri](https://github.com/kulttuuri)

## License

[MIT License](http://en.wikipedia.org/wiki/MIT_License)
