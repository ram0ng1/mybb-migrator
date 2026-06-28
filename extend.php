<?php

namespace Ramon\MybbMigrator;

use Flarum\Extend;
use Ramon\MybbMigrator\Api\Controller\CancelController;
use Ramon\MybbMigrator\Api\Controller\ComparePostController;
use Ramon\MybbMigrator\Api\Controller\LogController;
use Ramon\MybbMigrator\Api\Controller\RunStepController;
use Ramon\MybbMigrator\Api\Controller\SaveConnectionController;
use Ramon\MybbMigrator\Api\Controller\StatusController;
use Ramon\MybbMigrator\Api\Controller\TestConnectionController;
use Ramon\MybbMigrator\Auth\MybbPasswordChecker;
use Ramon\MybbMigrator\Console\FixCharsetCommand;
use Ramon\MybbMigrator\Console\GuiRunCommand;
use Ramon\MybbMigrator\Console\FixDiscussionSlugsCommand;
use Ramon\MybbMigrator\Console\FixEmojisCommand;
use Ramon\MybbMigrator\Console\FixFontBbcodeCommand;
use Ramon\MybbMigrator\Console\FixMentionSlugsCommand;
use Ramon\MybbMigrator\Console\FixPmParseCommand;
use Ramon\MybbMigrator\Console\FixPseudoListsCommand;
use Ramon\MybbMigrator\Console\FixQuotesCommand;
use Ramon\MybbMigrator\Console\FixSignaturesCommand;
use Ramon\MybbMigrator\Console\FixSizeBbcodeCommand;
use Ramon\MybbMigrator\Console\FixSmiliesCommand;
use Ramon\MybbMigrator\Console\FixSpacingCommand;
use Ramon\MybbMigrator\Console\ApplyNicknamesCommand;
use Ramon\MybbMigrator\Console\FixUsernamesCommand;
use Ramon\MybbMigrator\Console\FixUserMentionsCommand;
use Ramon\MybbMigrator\Console\RestoreQuoteMentionsCommand;
use Ramon\MybbMigrator\Console\RestoreQuotesCommand;
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
use Ramon\MybbMigrator\Console\RebuildFormattingCommand;
use Ramon\MybbMigrator\Console\RecoverProtectedPostsCommand;
use Ramon\MybbMigrator\Console\ReimportSignaturesCommand;
use Ramon\MybbMigrator\Console\MigrateSignaturesCommand;
use Ramon\MybbMigrator\Console\TestBioRenderCommand;
use Ramon\MybbMigrator\Console\TestCredentialsCommand;
use Ramon\MybbMigrator\Console\WipeCommand;

return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/signature.less'),

    (new Extend\Frontend('admin'))
        ->css(__DIR__ . '/less/admin.less')
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Settings())
        ->default('mybb-migrator.old_site_url', 'https://damnfineshave.com')
        // Segundos sem heartbeat (mtime do log) até o painel considerar um passo
        // travado. Alto de propósito: operações silenciosas longas (COUNT/DELETE
        // em massa) não devem ser marcadas como falha nem reabrir o guard.
        ->default('mybb-migrator.stale_seconds', 600),

    (new Extend\Auth())
        ->addPasswordChecker('mybb-legacy', MybbPasswordChecker::class),

    (new Extend\Routes('api'))
        ->get('/mybb-migrator/status', 'mybb-migrator.status', StatusController::class)
        ->post('/mybb-migrator/connection', 'mybb-migrator.connection.save', SaveConnectionController::class)
        ->post('/mybb-migrator/test', 'mybb-migrator.connection.test', TestConnectionController::class)
        ->post('/mybb-migrator/run', 'mybb-migrator.run', RunStepController::class)
        ->post('/mybb-migrator/cancel', 'mybb-migrator.cancel', CancelController::class)
        ->get('/mybb-migrator/log', 'mybb-migrator.log', LogController::class)
        ->get('/mybb-migrator/compare', 'mybb-migrator.compare', ComparePostController::class),

    (new Extend\Console())
        ->command(GuiRunCommand::class)
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
        ->command(FixDiscussionSlugsCommand::class)
        ->command(FixSizeBbcodeCommand::class)
        ->command(FixSmiliesCommand::class)
        ->command(FixSignaturesCommand::class)
        ->command(FixUsernamesCommand::class)
        ->command(ApplyNicknamesCommand::class)
        ->command(ReimportSignaturesCommand::class)
        ->command(MigrateSignaturesCommand::class)
        ->command(TestBioRenderCommand::class)
        ->command(NormalizeBbcodeCommand::class)
        ->command(FixPmParseCommand::class)
        ->command(FixPseudoListsCommand::class)
        ->command(FixFontBbcodeCommand::class)
        ->command(StripOrphanBbcodeCommand::class)
        ->command(RebuildFormattingCommand::class)
        ->command(FixSpacingCommand::class)
        ->command(MakeAdminCommand::class)
        ->command(MigrateSubscriptionsCommand::class)
        ->command(MigrateMessagesCommand::class)
        ->command(MigratePollsCommand::class)
        ->command(MigrateTradeFeedbackCommand::class)
        ->command(MigrateReviewsCommand::class)
        ->command(CompactQuotesCommand::class)
        ->command(RestoreQuotesCommand::class)
        ->command(FixTapatalkEmojiCommand::class)
        ->command(TestCredentialsCommand::class),
];
