<?php

namespace Ramon\MybbMigrator;

use Flarum\Extend;
use Ramon\MybbMigrator\Auth\MybbPasswordChecker;
use Ramon\MybbMigrator\Console\FixCharsetCommand;
use Ramon\MybbMigrator\Console\FixEmojisCommand;
use Ramon\MybbMigrator\Console\FixFontBbcodeCommand;
use Ramon\MybbMigrator\Console\FixMentionSlugsCommand;
use Ramon\MybbMigrator\Console\FixPmParseCommand;
use Ramon\MybbMigrator\Console\FixQuotesCommand;
use Ramon\MybbMigrator\Console\FixSignaturesCommand;
use Ramon\MybbMigrator\Console\FixSizeBbcodeCommand;
use Ramon\MybbMigrator\Console\FixSmiliesCommand;
use Ramon\MybbMigrator\Console\ApplyNicknamesCommand;
use Ramon\MybbMigrator\Console\FixUsernamesCommand;
use Ramon\MybbMigrator\Console\FixUserMentionsCommand;
use Ramon\MybbMigrator\Console\RestoreQuoteMentionsCommand;
use Ramon\MybbMigrator\Console\RevertIspoilerCommand;
use Ramon\MybbMigrator\Console\RevertMdStrikeSubCommand;
use Ramon\MybbMigrator\Console\RevertQuoteMentionsCommand;
use Ramon\MybbMigrator\Console\StripOrphanBbcodeCommand;
use Ramon\MybbMigrator\Console\MakeAdminCommand;
use Ramon\MybbMigrator\Console\MigrateAvatarsCommand;
use Ramon\MybbMigrator\Console\MigrateContentCommand;
use Ramon\MybbMigrator\Console\MigrateForumPermsCommand;
use Ramon\MybbMigrator\Console\MigrateGroupsCommand;
use Ramon\MybbMigrator\Console\MigratePermissionsCommand;
use Ramon\MybbMigrator\Console\MigrateLikesCommand;
use Ramon\MybbMigrator\Console\MigrateMessagesCommand;
use Ramon\MybbMigrator\Console\CompactQuotesCommand;
use Ramon\MybbMigrator\Console\FixTapatalkEmojiCommand;
use Ramon\MybbMigrator\Console\MigratePollsCommand;
use Ramon\MybbMigrator\Console\MigrateReviewsCommand;
use Ramon\MybbMigrator\Console\MigrateSubscriptionsCommand;
use Ramon\MybbMigrator\Console\MigrateTagsCommand;
use Ramon\MybbMigrator\Console\MigrateTradeFeedbackCommand;
use Ramon\MybbMigrator\Console\MigrateUsersCommand;
use Ramon\MybbMigrator\Console\NormalizeBbcodeCommand;
use Ramon\MybbMigrator\Console\RecoverProtectedPostsCommand;
use Ramon\MybbMigrator\Console\ReimportSignaturesCommand;
use Ramon\MybbMigrator\Console\TestBioRenderCommand;
use Ramon\MybbMigrator\Console\TestCredentialsCommand;
use Ramon\MybbMigrator\Console\WipeCommand;

return [
    (new Extend\Auth())
        ->addPasswordChecker('mybb-legacy', MybbPasswordChecker::class),

    (new Extend\Console())
        ->command(WipeCommand::class)
        ->command(MigrateGroupsCommand::class)
        ->command(MigrateUsersCommand::class)
        ->command(MigrateAvatarsCommand::class)
        ->command(MigrateTagsCommand::class)
        ->command(MigrateContentCommand::class)
        ->command(MigrateLikesCommand::class)
        ->command(MigratePermissionsCommand::class)
        ->command(MigrateForumPermsCommand::class)
        ->command(FixCharsetCommand::class)
        ->command(FixEmojisCommand::class)
        ->command(FixQuotesCommand::class)
        ->command(FixUserMentionsCommand::class)
        ->command(RevertQuoteMentionsCommand::class)
        ->command(RestoreQuoteMentionsCommand::class)
        ->command(RevertMdStrikeSubCommand::class)
        ->command(RevertIspoilerCommand::class)
        ->command(RecoverProtectedPostsCommand::class)
        ->command(FixMentionSlugsCommand::class)
        ->command(FixSizeBbcodeCommand::class)
        ->command(FixSmiliesCommand::class)
        ->command(FixSignaturesCommand::class)
        ->command(FixUsernamesCommand::class)
        ->command(ApplyNicknamesCommand::class)
        ->command(ReimportSignaturesCommand::class)
        ->command(TestBioRenderCommand::class)
        ->command(NormalizeBbcodeCommand::class)
        ->command(FixPmParseCommand::class)
        ->command(FixFontBbcodeCommand::class)
        ->command(StripOrphanBbcodeCommand::class)
        ->command(MakeAdminCommand::class)
        ->command(MigrateSubscriptionsCommand::class)
        ->command(MigrateMessagesCommand::class)
        ->command(MigratePollsCommand::class)
        ->command(MigrateTradeFeedbackCommand::class)
        ->command(MigrateReviewsCommand::class)
        ->command(CompactQuotesCommand::class)
        ->command(FixTapatalkEmojiCommand::class)
        ->command(TestCredentialsCommand::class),
];
