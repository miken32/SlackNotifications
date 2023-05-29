<?php

namespace RadiusOne\MediaWiki;

use Block;
use Exception;
use ManualLogEntry;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use MWDebug;
use SpecialBlock;
use SpecialContributions;
use Title;
use User;
use UserRightsPage;
use WikiPage;

class SlackNotifications
{
    /** @var MediaWikiServices The services object */
    private static $mwServices = null;

    /** @var Config The mediawiki site config object */
    private static $mwConfig = null;

    /** @var Config The extension config object */
    private static $snConfig = null;

    const RED = "#b21717";
    const YELLOW = "#d6be37";
    const GREEN = "#299b29";

    /**
     * Initializes (if needed) and returns the site config object
     *
     * @return Config
     */
    private static function getMwConfig()
    {
        if (self::$mwConfig == null) {
            if (self::$mwServices === null) {
                self::$mwServices = MediaWikiServices::getInstance();
            }
            self::$mwConfig = self::$mwServices->getMainConfig();
        }
        return self::$mwConfig;
    }

    /**
     * Initializes (if needed) and returns the extension config object
     *
     * @return Config
     */
    private static function getExtConfig()
    {
        if (self::$snConfig == null) {
            if (self::$mwServices === null) {
                self::$mwServices = MediaWikiServices::getInstance();
            }
            self::$snConfig = self::$mwServices->getConfigFactory()->makeConfig('SlackNotifications');
        }
        return self::$snConfig;
    }

    /**
     * Gets nice HTML text for user containing the link to user page
     * or links to user site, groups editing, talk and contribs pages.
     * @param User $user The user object
     * @param bool $actionLinks Whether to return the user link or the user action links
     * @return string Links formatted for a Slack message
     */
    private static function getSlackUserText(User $user, $actionLinks = false)
    {
        if ($actionLinks) {
            $block   = new SpecialBlock();
            $rights  = new UserrightsPage();
            $contrib = new SpecialContributions();
            return sprintf(
                "<%s|block> | <%s|groups> | <%s|talk> | <%s|contribs>",
                $block->getPageTitle()->getFullUrl() . "/" . urlencode($user),
                $rights->getPageTitle()->getFullUrl() . "/" . urlencode($user),
                $user->getTalkPage()->getFullUrl(),
                $contrib->getPageTitle()->getFullUrl() . "/" . urlencode($user)
            );
        }
        $config     = self::getExtConfig();
        $wgSlackAts = $config->get("SlackUsersAreWikiUsers");
        $title      = $user->getUserPage();
        $output     = sprintf("<%s|%s>", $title->getFullUrl(), $user);
        if ($wgSlackAts) {
            $output .= " (<@$user>)";
        }
        return $output;
    }

    /**
     * Gets nice HTML text for article containing the link to article page
     * and also into edit, delete and article history pages.
     * @param WikiPage $article The page object
     * @param bool $diff Whether to include a link to the diff
     * @return string
     */
    private static function getSlackArticleText(WikiPage $article, $actionLinks = false, $diff = false)
    {
        $title = $article->getTitle();

        if ($actionLinks) {
            $out = sprintf(
                "<%s|edit> | <%s|delete> | <%s|history>",
                $title->getFullUrl(array("action"=>"edit")),
                $title->getFullUrl(array("action"=>"delete")),
                $title->getFullUrl(array("action"=>"history"))
            );
            if ($diff) {
                $revid = $article->getRevisionRecord()->getID();
                $out .= sprintf(
                    " | <%s|diff>",
                    $title->getFullUrl(array("type"=>"revision", "diff"=>$revid))
                );
            }
            return $out;
        }
        return sprintf("<%s|%s>", $title->getFullUrl(), $title->getFullText());
    }

    /**
     * Gets nice HTML text for title object containing the link to article page
     * and also into edit, delete and article history pages.
     * @param Title $title The title object of the page
     * @param bool $actionLinks Whether to include edit, delete, and history links
     * @return string
     */
    private static function getSlackTitleText(Title $title, bool $actionLinks = false)
    {
        if ($actionLinks) {
            return sprintf(
                "<%s|edit> | <%s|delete> | <%s|history>",
                $title->getFullUrl(array("action"=>"edit")),
                $title->getFullUrl(array("action"=>"delete")),
                $title->getFullUrl(array("action"=>"history"))
            );
        }
        return sprintf("<%s|%s>", $title->getFullUrl(), $title->getFullText());
    }

    /**
     * Determines if a title is a prefix match for an entry in the excluded list
     * @param string $title The page title
     * @return boolean Whether the title matched the list
     */
    private static function isExcluded(Title $title)
    {
        $config = self::getExtConfig();
        $nspace = $title->getNsText();
        $spaces = $config->get("SlackExcludedNamespaces");
        if (is_array($spaces) && count($spaces) > 0) {
            $result = array_filter(
                $spaces,
                function ($v) use ($nspace) {
                    return strcmp($nspace, $v) === 0;
                }
            );
            if (count($result)) {
                return true;
            }
        }

        $btitle = $title->getBaseText();
        $titles = $config->get("SlackExcludedTitles");
        if (is_array($titles) && count($titles) > 0) {
            $result = array_filter(
                $titles,
                function ($v) use ($btitle) {
                    return strpos($btitle, $v) === 0;
                }
            );
            if (count($result)) {
                return true;
            }
        }

        $ftitle = $title->getFullText();
        $legacy = $config->get("SlackExcludeNotificationsFrom");
        if (is_array($legacy) && count($legacy) > 0) {
            $result = array_filter(
                $legacy,
                function ($v) use ($ftitle) {
                    return strpos($ftitle, $v) === 0;
                }
            );
            if (count($result)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determines if a title is a prefix match for an entry in the included list
     * @param string $title The page title
     * @return boolean Whether the title matched the list
     */
    private static function isIncluded(Title $title)
    {
        $config = self::getExtConfig();
        $nspace = $title->getNsText();
        $spaces = $config->get("SlackIncludedNamespaces");
        if (is_array($spaces) && count($spaces) > 0) {
            $result = array_filter(
                $spaces,
                function ($v) use ($nspace) {
                    return strcmp($nspace, $v) === 0;
                }
            );
            if (count($result)) {
                return true;
            }
        }

        $btitle = $title->getBaseText();
        $titles = $config->get("SlackIncludedTitles");
        if (is_array($titles) && count($titles) > 0) {
            $result = array_filter(
                $titles,
                function ($v) use ($btitle) {
                    return strpos($btitle, $v) === 0;
                }
            );
            if (count($result)) {
                return true;
            }
        }

        // default to true if no entries given
        return count($spaces) === 0 && count($titles) === 0;
    }

    /**
     * Log a message to the debug console
     * @param string $message The message
     * #param bool $warning When true, emit warning messages to the page as well
     * @return void
     * @see https://www.mediawiki.org/wiki/Debugging_toolbar
     */
    private static function log(string $message, bool $warning = false)
    {
        MWDebug::init();
        if ($warning) {
            MWDebug::warning($message);
        } else {
            MWDebug::log($message);
        }
    }

    /**
     * Occurs after the save page request has been processed.
     * @param WikiPage $article The page object that was updated
     * @param UserIdentity $user The user making the change
     * @param string $summary The edit summary
     * @param int $flags Bitfield of options
     * @param RevisionRecord $revision The revision object created by the edit
     * @param EditResult $result The result of the edit
     * @return void
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
     */
    public static function articleSaved(
        WikiPage $article,
        UserIdentity $user,
        string $summary,
        int $flags,
        RevisionRecord $revision,
        EditResult $result
    ) {
        self::log("Entering SlackNotificationsCore::articleSaved()");
        $config                           = self::getExtConfig();
        $wgSlackIncludeDiffSize           = $config->get("SlackIncludeDiffSize");
        $wgSlackIncludePageUrls           = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls           = $config->get("SlackIncludeUserUrls");
        $wgSlackIgnoreMinorEdits          = $config->get("SlackIgnoreMinorEdits");
        $wgSlackNotificationEditedArticle = $config->get("SlackNotificationEditedArticle");

        $user = User::newFromIdentity($user);
        $isMinor = $flags & \EDIT_MINOR;
        $isNew = $result->isNew();
        $prevRev = MediaWikiServices::getInstance()->getRevisionLookup()->getPreviousRevision($revision);

        if (
            !$wgSlackNotificationEditedArticle ||
            self::isExcluded($article->getTitle()) ||
            !self::isIncluded($article->getTitle()) ||
            // Skip minor edits, or null revisions (eg protecting articles)
            ($isMinor && $wgSlackIgnoreMinorEdits) ||
            $result->isNullEdit() ||
            // Do not announce newly added file uploads as articles...
            ($isNew && $article->getTitle()->getNsText() === "File")
        ) {
            return;
        }

        $message = sprintf("A page was %s", $isNew ? "created" : "updated");
        $attach[] = array(
            "fallback"   => sprintf(
                "%s has %s %s",
                $user,
                $isNew ? "created" : "updated",
                $article->getTitle()->getFullText()
            ),
            "color"      => $isNew ? self::GREEN : self::YELLOW,
            "title"      => $article->getTitle()->getFullText(),
            "title_link" => $article->getTitle()->getFullUrl(),
            "fields"     => array(),
            "ts"         => wfTimestamp(TS_UNIX, $revision->getTimestamp()),
            "text"       => sprintf(
                "Page was %s%s by %s %s\nSummary: %s",
                $isNew ? "created" : "edited",
                (!$isNew && $isMinor ? " (minor)" : ""),
                self::getSlackUserText($user),
                (
                    $wgSlackIncludeDiffSize ?
                    sprintf("(%+d bytes%s)", $revision->getSize() - $prevRev->getSize(), $isNew ? "" : " change") :
                    ""
                ),
                $summary ? "_{$summary}_" : "none provided"
            ),
        );
        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "Page Links",
                "short" => "true",
                "value" => self::getSlackArticleText($article, true, true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }
        self::sendNotification($message, $user, $attach);
        self::log("Exiting SlackNotificationsCore::articleSaved()");
    }

    /**
     * Occurs after the delete page request has been processed.
     * @param ProperPageIdentity $article The page that was deleted
     * @param Authority $deleter The user that performed the deletion
     * @param string $reason The reason given for the deletion
     * @param int $id The database ID of the deleted page
     * @param RevisionRecord $rev The deleted page revision
     * @param ManualLogEntry $logEntry The log entry recording the deletion
     * @return void
     * @see http://www.mediawiki.org/wiki/Manual:Hooks/PageDeleteComplete
     */
    public static function articleDeleted(
        ProperPageIdentity $article,
        Authority $deleter,
        string $reason,
        int $id,
        RevisionRecord $rev,
        ManualLogEntry $logEntry,
        int $archivedRevCount
    ) {
        self::log("Entering SlackNotificationsCore::articleDeleted()");
        $config                            = self::getExtConfig();
        $wgSlackIncludePageUrls            = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls            = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationRemovedArticle = $config->get("SlackNotificationRemovedArticle");

        $user = $deleter->getUser();
        if (
            !$wgSlackNotificationRemovedArticle ||
            self::isExcluded($article->getTitle()) ||
            !self::isIncluded($article->getTitle())
        ) {
            return;
        }

        $message = "A page was deleted";
        $attach[] = array(
            "fallback"   => sprintf("%s has deleted %s", $user, $article->getTitle()->getFullText()),
            "color"      => self::RED,
            "title"      => $article->getTitle()->getFullText(),
            "title_link" => $article->getTitle()->getFullUrl(),
            "fields"     => array(),
            "ts"         => wfTimestamp(TS_UNIX, $logEntry->getTimestamp()),
            "text"       => sprintf(
                "Page was deleted by %s\nReason: %s",
                self::getSlackUserText($user),
                $reason ? "_{$reason}_" : "none provided"
            ),
        );

        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "Page Links",
                "short" => "true",
                "value" => self::getSlackArticleText($article, true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
        self::log("Exiting SlackNotificationsCore::articleDeleted()");
    }

    /**
     * Occurs after a page has been moved.
     * @param LinkTarget $target The page link before the move
     * @param LinkTarget $newTarget The page link after the move
     * @param UserIdentity $user The user performing the move
     * @param int $oldid The database ID of the page before the move
     * @param int $newid The database ID of the redirection page or 0 if one wasn't created
     * @param string $reason The reason for the move
     * @param Revision $revision The revision object created by the move
     * @return void
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/TitleMoveComplete
     */
    public static function articleMoved(
        LinkTarget $target,
        LinkTarget $newTarget,
        UserIdentity $user,
        $oldId,
        $newId,
        $reason,
        RevisionRecord $revision
    ) {
        self::log("Entering SlackNotificationsCore::articleMoved()");
        $config                           = self::getExtConfig();
        $wgSlackIncludePageUrls           = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls           = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationMovedArticle  = $config->get("SlackNotificationMovedArticle");

        $title = Title::newFromLinkTarget($target);
        $newTitle = Title::newFromLinkTarget($newTarget);
        $user = User::newFromIdentity($user);

        if (
            !$wgSlackNotificationMovedArticle ||
            self::isExcluded($title) ||
            self::isExcluded($newTitle) ||
            !self::isIncluded($title) ||
            !self::isIncluded($newTitle)
        ) {
            return;
        }

        $message = "A page was moved";
        $attach[] = array(
            "fallback"   => sprintf("%s has moved %s to %s", $user, $title->getFullText(), $newTitle->getFullText()),
            "color"      => self::YELLOW,
            "title"      => $title->getFullText(),
            "title_link" => $title->getFullUrl(),
            "fields"     => array(),
            "ts"         => wfTimestamp(TS_UNIX, $revision->getTimestamp()),
            "text"       => sprintf(
                "Page was moved to %s by %s\nReason: %s",
                self::getSlackTitleText($newTitle),
                self::getSlackUserText($user),
                $reason ? "_{$reason}_" : "none given"
            ),
        );

        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "New Page Links",
                "short" => "true",
                "value" => self::getSlackTitleText($newTitle, true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
        self::log("Exiting SlackNotificationsCore::articleMoved()");
    }

    /**
     * Occurs after the protect article request has been processed.
     * @param WikiPage $article The page that was protected
     * @param User $user The user that protected the page
     * @param array $protect An array of restrictions indexed by permission
     * @param string $reason The reason for the change in protection
     * @param bool $moveonly True if the protection is for moves only
     * @return void
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleProtectComplete
     */
    public static function articleProtected(
        WikiPage $article,
        User $user,
        $protect,
        $reason,
        $moveOnly = false
    ) {
        self::log("Entering SlackNotificationsCore::articleProtected()");
        $config                              = self::getExtConfig();
        $wgSlackIncludePageUrls              = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls              = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationProtectedArticle = $config->get("SlackNotificationProtectedArticle");

        if (
            !$wgSlackNotificationProtectedArticle ||
            self::isExcluded($article->getTitle()) ||
            !self::isIncluded($article->getTitle())
        ) {
            return;
        }

        foreach ($protect as $permission=>$groupname) {
            // if there's a restriction in place, the value is a group name
            if ($groupname) {
                $isProtecting = true;
                break;
            }
        }

        $message = "A page was protected";
        $attach[] = array(
            "fallback"   => sprintf("%s has %s %s", $user, $protect ? "protected" : "unprotected", $article->getTitle()->getFullText()),
            "color"      => self::YELLOW,
            "title"      => $article->getTitle()->getFullText(),
            "title_link" => $article->getTitle()->getFullUrl(),
            "fields"     => array(),
            "ts"         => wfTimestamp(TS_UNIX, $article->getRevision()->getTimestamp()),
            "text"       => sprintf(
                "Page had protection %s by %s\nReason: %s",
                $isProtecting ? "changed" : "removed",
                self::getSlackUserText($user),
                $reason ? "_${reason}_" : "none given"
            ),
        );

        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "Page Links",
                "short" => "true",
                "value" => self::getSlackArticleText($article, true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
        self::log("Exiting SlackNotificationsCore::articleProtected()");
    }

    /**
     * Called after a user account is created.
     * @param User $user The new user
     * @param bool $byEmail True if the user was created "by email"
     * @return void
     * @see http://www.mediawiki.org/wiki/Manual:Hooks/AddNewAccount
     */
    public static function newUserAccount(User $user, bool $autoCreated)
    {
        self::log("Entering SlackNotificationsCore::newUserAccount()");
        $config                     = self::getExtConfig();
        $wgSlackShowNewUserIP       = $config->get("SlackShowNewUserIP");
        $wgSlackIncludeUserUrls     = $config->get("SlackIncludeUserUrls");
        $wgSlackShowNewUserEmail    = $config->get("SlackShowNewUserEmail");
        $wgSlackNotificationNewUser = $config->get("SlackNotificationNewUser");
        $wgSlackShowNewUserFullName = $config->get("SlackShowNewUserFullName");

        if (!$wgSlackNotificationNewUser) {
            return;
        }

        $message = "A user was created";
        $attach[] = array(
            "fallback"   => sprintf("User %s was created", $user),
            "color"      => self::GREEN,
            "title"      => $user,
            "title_link" => $user->getUserPage->getFullUrl(),
            "text"       => sprintf("New user account was created"),
            "fields"     => array(),
            "ts"         => wfTimestamp(TS_UNIX, $user->getRegistration()),
        );

        if ($wgSlackShowNewUserEmail) {
            try {
                $attach[0]["fields"][] = array("title" => "Email", "value" => $user->getEmail(), "short" => true);
            } catch (Exception $e) {}
        }
        if ($wgSlackShowNewUserFullName) {
            try {
                $attach[0]["fields"][] = array("title" => "Name", "value" => $user->getRealName(), "short" => true);
            } catch (Exception $e) {}
        }
        if ($wgSlackShowNewUserIP) {
            try {
                $attach[0]["fields"][] = array("title" => "IP", "value" => $user->getRequest()->getIP(), "short" => true);
            } catch (Exception $e) {}
        }

        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "false",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
        self::log("Exiting SlackNotificationsCore::newUserAccount()");
    }

    /**
     * Called when a file upload has completed.
     * @param UploadBase $image The upload object
     * @return void
     * @see http://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete
     */
    public static function fileUploaded(UploadBase $image)
    {
        self::log("Entering SlackNotificationsCore::fileUploaded()");
        $config                        = self::getExtConfig();
        $wgSlackIncludePageUrls        = $config->get("SlackIncludePageUrls");
        $wgSlackIncludeUserUrls        = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationFileUpload = $config->get("SlackNotificationFileUpload");

        if (
            !$wgSlackNotificationFileUpload ||
            self::isExcluded($image->getLocalFile()->getTitle()) ||
            !self::isIncluded($image->getLocalFile()->getTitle())
        ) {
            return;
        }

        $file = $image->getLocalFile();
        $user = $file->getUser("object");
        if (is_numeric($user)) {
            $user = User::newFromId($user);
        }

        $message = "A file was uploaded";
        $attach[] = array(
            "fallback"   => sprintf("%s has uploaded %s", $user, $file->getTitle()->getFullText()),
            "color"      => self::GREEN,
            "title"      => $file->getTitle()->getFullText(),
            "title_link" => $file->getTitle()->getFullUrl(),
            "fields"     => array(),
            "ts"         => wfTimestamp(TS_UNIX, $file->getTimestamp()),
            "text"       => sprintf(
                "File was uploaded by %s\nSummary: %s",
                self::getSlackUserText($user),
                $file->getDescription() ? "_" . $file->getDescription() . "_" : "none given"
            ),
        );

        if (!$file->isExpensiveToThumbnail()) {
            $thumb_url = $file->getThumbnails()[0];
            $thumb_file = self::resolveVirtualURL($thumb_url, $file);
            if ($thumb_file && file_exists($thumb_file)) {
                $attach[0]["thumb_url"] = sprintf(
                    "data:%s;base64,%s",
                    $file->getMimeType(),
                    base64_encode(file_get_contents($thumb_file))
                );
            }
        }

        $attach[0]["fields"][] = array("title" => "Type", "short" => "true", "value" => $file->getMimeType());

        $size = $file->size;
        if ($size > 1024 * 1024 * 1024) {
            $size = sprintf("%f GB", round($size / 1024 / 1024 / 1024, 2));
        } elseif ($size > 1024 * 1024) {
            $size = sprintf("%f MB", round($size / 1024 / 1024, 1));
        } elseif ($size > 1024) {
            $size = sprintf("%d kB", floor($size / 1024));
        } else {
            $size = sprintf("%d B", $size);
        }

        $attach[0]["fields"][] = array("title" => "Size", "short" => "true", "value" => $size);

        if ($wgSlackIncludePageUrls) {
            $attach[0]["fields"][] = array(
                "title" => "File Links",
                "short" => "true",
                "value" => self::getSlackTitleText($image->getTitle(), true),
            );
        }
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($user, true),
            );
        }

        self::sendNotification($message, $user, $attach);
        self::log("Exiting SlackNotificationsCore::fileUpload()");
    }

    /**
     * Occurs after the request to block an IP or user has been processed
     * @param Block $block The user block object
     * @param User $user The user performing the block
     * @return void
     * @see http://www.mediawiki.org/wiki/Manual:MediaWiki_hooks/BlockIpComplete
     */
    public static function userBlocked(Block $block, User $user)
    {
        self::log("Entering SlackNotificationsCore::userBlocked()");
        $config                         = self::getExtConfig();
        $wgSlackIncludeUserUrls         = $config->get("SlackIncludeUserUrls");
        $wgSlackNotificationBlockedUser = $config->get("SlackNotificationBlockedUser");

        if (!$wgSlackNotificationBlockedUser) {
            return;
        }

        $blockpage = new SpecialBlockList();
        $message   = "A user was blocked";
        $attach[]  = array(
            "fallback"   => sprintf("%s has blocked %s", $user, $block->getTarget()->getName()),
            "color"      => self::RED,
            "title"      => $block->getTarget()->getName(),
            "title_link" => $block->getTarget()->getUserPage()->getFullUrl(),
            "fields"     => array(),
            "ts"         => wfTimestamp(TS_UNIX, $block->mTimestamp),
            "text"       => sprintf(
                "User was blocked by %s\nReason: _%s_.",
                self::getSlackUserText($user),
                $block->mReason ?? $block->getReasonComment()?->text ?? "none given"
            ),
        );
        $attach[0]["fields"][] = array("title" => "Expiry", "short" => "true", "value" => $block->mExpiry);
        $attach[0]["fields"][] = array(
            "title" => "More Info",
            "short" => "true",
            "value" => sprintf("<%s|%s>", $blockpage->getPageTitle()->getFullUrl(), "Block list"),
        );
        if ($wgSlackIncludeUserUrls) {
            $attach[0]["fields"][] = array(
                "title" => "User Links",
                "short" => "true",
                "value" => self::getSlackUserText($block->getTarget(), true),
            );
        }
        self::sendNotification($message, $user, $attach);
        self::log("Exiting SlackNotificationsCore::userBlocked()");
    }

    /**
     * Sends the message to the Slack webhook
     *
     * @param string $message Message to be sent.
     * @param User $user The Mediawiki user object.
     * @param array $attach Array of attachment objects to be sent.
     * @return void
     * @see https://api.slack.com/incoming-webhooks
     */
    private static function sendNotification($message, User $user, $attach = array())
    {
        self::log("Entering SlackNotificationsCore::sendNotification()");
        $mwConfig = self::getMwConfig();
        $config   = self::getExtConfig();

        $wgSitename                = $mwConfig->get("Sitename");
        $wgHTTPProxy               = $mwConfig->get("HTTPProxy");
        $wgSlackEmoji              = $config->get("SlackEmoji");
        $wgSlackFromName           = $config->get("SlackFromName");
        $wgSlackRoomName           = $config->get("SlackRoomName");
        $wgSlackExcludeGroup       = $config->get("SlackExcludeGroup");
        $wgSlackIncomingWebhookUrl = $config->get("SlackIncomingWebhookUrl");

        if ($wgSlackExcludeGroup && $user->isAllowed($wgSlackExcludeGroup)) {
            return; // Users with the permission suppress notifications
        }

        $postData = array(
            "text"        => $message,
            "channel"     => $wgSlackRoomName ?: null,
            "username"    => $wgSlackFromName ?: $wgSitename,
            "icon_emoji"  => $wgSlackEmoji    ?: null,
            "attachments" => $attach,
        );
        $postData = json_encode($postData);
        self::log("Post data: $postData");

        if (ini_get("allow_url_fopen")) {
            $options = array(
                "http" => array(
                    "header"  => "Content-type: application/json",
                    "method"  => "POST",
                    "content" => $postData,
                    "proxy"   => $wgHTTPProxy ?: null,
                    "request_fulluri" => (bool)$wgHTTPProxy
                ),
            );
            $context = stream_context_create($options);
            file_get_contents($wgSlackIncomingWebhookUrl, false, $context);
        } elseif (extension_loaded("curl")) {
            $h = curl_init();
            curl_setopt_array($h, array(
                CURLOPT_URL        => $wgSlackIncomingWebhookUrl,
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => array("Content-Type: application/json"),
                CURLOPT_PROXY      => $wgHTTPProxy ?: null,
            ));
            curl_exec($h);
            curl_close($h);
        }
        self::log("Exiting SlackNotificationsCore::sendNotification()");
    }

    private static function resolveVirtualURL($url, $file)
    {
        $u = parse_url($url);
        if (
            (empty($u["scheme"]) || $u["scheme"] !== "mwstore") ||
            (empty($u["host"])   || $u["host"]   !== "local-backend")
        ) {
            return false;
        }
        $path = $file->getRepo()->getLocalReference($url);
        return $path ? $path : false;
    }
}
