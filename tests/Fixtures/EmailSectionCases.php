<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Fixtures;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Email;

/** Literal Email request and payload oracle, independently transcribed without generated specifications. */
final class EmailSectionCases
{
    /** @return iterable<string, array{string, Closure(Email): mixed, string, string, ?string}> */
    public static function operations(): iterable
    {
        yield 'account_name' => [
            'account_name',
            static fn (Email $email): mixed => $email->accountName(),
            'GET',
            '',
            '"user@example.test"',
        ];
        yield 'add_auto_responder' => [
            'add_auto_responder',
            static fn (Email $email): mixed => $email->addAutoResponder(
                body: 'Away',
                domain: 'example.test',
                email: 'user',
                from: 'User',
                interval: 24,
                isHtml: false,
                start: 0,
                stop: 0,
                subject: 'Away',
            ),
            'POST',
            'body=Away&domain=example.test&email=user&from=User&interval=24&is_html=0&start=0&stop=0&'
            . 'subject=Away',
            'null',
        ];
        yield 'add_domain_forwarder' => [
            'add_domain_forwarder',
            static fn (Email $email): mixed => $email->addDomainForwarder('target.test', 'example.test'),
            'POST',
            'destdomain=target.test&domain=example.test',
            '"example.test"',
        ];
        yield 'add_forwarder' => [
            'add_forwarder',
            static fn (Email $email): mixed => $email->addForwarder('example.test', 'user@example.test', 'fwd'),
            'POST',
            'domain=example.test&email=user%40example.test&fwdopt=fwd',
            '[{"domain":"example.test","email":"user","forward":"target@example.test"}]',
        ];
        yield 'add_list' => [
            'add_list',
            static fn (Email $email): mixed => $email->addList('example.test', 'news', 'secret'),
            'POST',
            'domain=example.test&list=news&password=secret',
            'null',
        ];
        yield 'add_mailman_delegates' => [
            'add_mailman_delegates',
            static fn (Email $email): mixed => $email->addMailmanDelegates('one@example.test,two@example.test', 'news'),
            'POST',
            'delegates=one%40example.test%2Ctwo%40example.test&list=news',
            '{"delegates":["one@example.test"],"metadata":{"transformed":1}}',
        ];
        yield 'add_mx' => [
            'add_mx',
            static fn (Email $email): mixed => $email->addMx('example.test', 'mail.example.test', 5),
            'POST',
            'domain=example.test&exchanger=mail.example.test&priority=5',
            '{"checkmx":{"changed":1,"detected":"local","isprimary":1,"issecondary":0,"local":1,"mxch'
            . 'eck":"local","remote":0,"secondary":0,"warnings":[]},"results":"added","status":1,"statu'
            . 'smsg":"ok"}',
        ];
        yield 'add_pop' => [
            'add_pop',
            static fn (Email $email): mixed => $email->addPop('user', 'secret'),
            'POST',
            'email=user&password=secret',
            '"user@example.test"',
        ];
        yield 'add_spam_filter' => [
            'add_spam_filter',
            static fn (Email $email): mixed => $email->addSpamFilter(),
            'POST',
            '',
            'null',
        ];
        yield 'browse_mailbox' => [
            'browse_mailbox',
            static fn (Email $email): mixed => $email->browseMailbox(),
            'GET',
            '',
            '[{"depth":0,"file":"INBOX","fullpath":"/mail","isleaf":1,"ismailbox":1,"mtime":123,"path'
            . '":"/mail","relpath":".","type":"dir"}]',
        ];
        yield 'change_mx' => [
            'change_mx',
            static fn (Email $email): mixed => $email->changeMx('example.test', 'new.test', 'old.test', 15),
            'POST',
            'domain=example.test&exchanger=new.test&oldexchanger=old.test&priority=15',
            '{"checkmx":{"changed":0,"warnings":[]},"results":"changed","status":1,"statusmsg":"ok"}',
        ];
        yield 'check_fastmail' => [
            'check_fastmail',
            static fn (Email $email): mixed => $email->checkFastmail(),
            'GET',
            '',
            '"fastmail"',
        ];
        yield 'count_auto_responders' => [
            'count_auto_responders',
            static fn (Email $email): mixed => $email->countAutoResponders(),
            'GET',
            '',
            '0',
        ];
        yield 'count_filters' => [
            'count_filters',
            static fn (Email $email): mixed => $email->countFilters(),
            'GET',
            '',
            '0',
        ];
        yield 'count_forwarders' => [
            'count_forwarders',
            static fn (Email $email): mixed => $email->countForwarders(),
            'GET',
            '',
            '0',
        ];
        yield 'count_lists' => [
            'count_lists',
            static fn (Email $email): mixed => $email->countLists(),
            'GET',
            '',
            '0',
        ];
        yield 'count_pops' => [
            'count_pops',
            static fn (Email $email): mixed => $email->countPops(),
            'GET',
            '',
            '0',
        ];
        yield 'delete_auto_responder' => [
            'delete_auto_responder',
            static fn (Email $email): mixed => $email->deleteAutoResponder('user@example.test'),
            'POST',
            'email=user%40example.test',
            'null',
        ];
        yield 'delete_domain_forwarder' => [
            'delete_domain_forwarder',
            static fn (Email $email): mixed => $email->deleteDomainForwarder('example.test'),
            'POST',
            'domain=example.test',
            'null',
        ];
        yield 'delete_filter' => [
            'delete_filter',
            static fn (Email $email): mixed => $email->deleteFilter('user@example.test', 'coffee'),
            'POST',
            'account=user%40example.test&filtername=coffee',
            'null',
        ];
        yield 'delete_forwarder' => [
            'delete_forwarder',
            static fn (Email $email): mixed => $email->deleteForwarder('user@example.test', 'target@example.test'),
            'POST',
            'address=user%40example.test&forwarder=target%40example.test',
            'null',
        ];
        yield 'delete_held_messages' => [
            'delete_held_messages',
            static fn (Email $email): mixed => $email->deleteHeldMessages('user@example.test'),
            'POST',
            'email=user%40example.test',
            '0',
        ];
        yield 'delete_list' => [
            'delete_list',
            static fn (Email $email): mixed => $email->deleteList('news'),
            'POST',
            'list=news',
            'null',
        ];
        yield 'delete_mx' => [
            'delete_mx',
            static fn (Email $email): mixed => $email->deleteMx('example.test', 'mail.test', 15),
            'POST',
            'domain=example.test&exchanger=mail.test&priority=15',
            '{"checkmx":{"warnings":"notice"},"results":"deleted","status":1,"statusmsg":"ok"}',
        ];
        yield 'delete_pop' => [
            'delete_pop',
            static fn (Email $email): mixed => $email->deletePop('user'),
            'POST',
            'email=user',
            'null',
        ];
        yield 'disable_filter' => [
            'disable_filter',
            static fn (Email $email): mixed => $email->disableFilter('user@example.test', 'coffee'),
            'POST',
            'account=user%40example.test&filtername=coffee',
            '{"filtername":"coffee","updated":1}',
        ];
        yield 'disable_mailbox_autocreate' => [
            'disable_mailbox_autocreate',
            static fn (Email $email): mixed => $email->disableMailboxAutocreate('user@example.test'),
            'POST',
            'email=user%40example.test',
            '1',
        ];
        yield 'disable_spam_assassin' => [
            'disable_spam_assassin',
            static fn (Email $email): mixed => $email->disableSpamAssassin(),
            'POST',
            '',
            'null',
        ];
        yield 'disable_spam_autodelete' => [
            'disable_spam_autodelete',
            static fn (Email $email): mixed => $email->disableSpamAutodelete(),
            'POST',
            '',
            '{"spam_auto_delete":0}',
        ];
        yield 'disable_spam_box' => [
            'disable_spam_box',
            static fn (Email $email): mixed => $email->disableSpamBox(),
            'POST',
            '',
            'null',
        ];
        yield 'dispatch_client_settings' => [
            'dispatch_client_settings',
            static function (Email $email): mixed {
                $email->dispatchClientSettings('user@example.test', 'recipient@example.test');

                return null;
            },
            'POST',
            'account=user%40example.test&to=recipient%40example.test',
            null,
        ];
        yield 'edit_pop_quota' => [
            'edit_pop_quota',
            static fn (Email $email): mixed => $email->editPopQuota('example.test', 'user'),
            'POST',
            'domain=example.test&email=user',
            'null',
        ];
        yield 'enable_filter' => [
            'enable_filter',
            static fn (Email $email): mixed => $email->enableFilter('user@example.test', 'coffee'),
            'POST',
            'account=user%40example.test&filtername=coffee',
            '{"filtername":"coffee","updated":1}',
        ];
        yield 'enable_mailbox_autocreate' => [
            'enable_mailbox_autocreate',
            static fn (Email $email): mixed => $email->enableMailboxAutocreate('user@example.test'),
            'POST',
            'email=user%40example.test',
            '1',
        ];
        yield 'enable_spam_assassin' => [
            'enable_spam_assassin',
            static fn (Email $email): mixed => $email->enableSpamAssassin(),
            'POST',
            '',
            'null',
        ];
        yield 'enable_spam_box' => [
            'enable_spam_box',
            static fn (Email $email): mixed => $email->enableSpamBox(),
            'POST',
            '',
            'null',
        ];
        yield 'export_lists' => [
            'export_lists',
            static fn (Email $email): mixed => $email->exportLists(),
            'POST',
            '',
            'null',
        ];
        yield 'fetch_charmaps' => [
            'fetch_charmaps',
            static fn (Email $email): mixed => $email->fetchCharmaps(),
            'GET',
            '',
            '[{"map":"UTF-8"}]',
        ];
        yield 'fts_rescan_mailbox' => [
            'fts_rescan_mailbox',
            static fn (Email $email): mixed => $email->ftsRescanMailbox('user@example.test'),
            'POST',
            'account=user%40example.test',
            '1',
        ];
        yield 'generate_mailman_otp' => [
            'generate_mailman_otp',
            static fn (Email $email): mixed => $email->generateMailmanOtp('news'),
            'POST',
            'list=news',
            '"opaque-otp"',
        ];
        yield 'get_auto_responder' => [
            'get_auto_responder',
            static fn (Email $email): mixed => $email->getAutoResponder('user'),
            'GET',
            'email=user',
            '{"body":"Away","charset":"utf-8","from":"User","interval":24,"is_html":0,"start":0,"stop'
            . '":0,"subject":"Away"}',
        ];
        yield 'get_charsets' => [
            'get_charsets',
            static fn (Email $email): mixed => $email->getCharsets(),
            'GET',
            '',
            '["utf-8","iso-8859-1"]',
        ];
        yield 'get_client_settings' => [
            'get_client_settings',
            static fn (Email $email): mixed => $email->getClientSettings(),
            'GET',
            '',
            '{"account":"user","activesync_available":0,"activesync_host":"mail.test","activesync_por'
            . 't":443,"activesync_username":"user","display":"User","domain":"example.test","from_archi'
            . 'ving":0,"has_plaintext_authentication":0,"inbox_host":"mail.test","inbox_insecure_port":'
            . '143,"inbox_port":993,"inbox_service":"imap","inbox_username":"user","mail_domain":"examp'
            . 'le.test","smtp_host":"mail.test","smtp_insecure_port":25,"smtp_port":465,"smtp_username"'
            . ':"user"}',
        ];
        yield 'get_default_email_quota' => [
            'get_default_email_quota',
            static fn (Email $email): mixed => $email->getDefaultEmailQuota(),
            'GET',
            '',
            '0',
        ];
        yield 'get_default_email_quota_mib' => [
            'get_default_email_quota_mib',
            static fn (Email $email): mixed => $email->getDefaultEmailQuotaMib(),
            'GET',
            '',
            '500',
        ];
        yield 'get_disk_usage' => [
            'get_disk_usage',
            static fn (Email $email): mixed => $email->getDiskUsage('example.test', 'user'),
            'GET',
            'domain=example.test&user=user',
            '{"diskused":1.5,"domain":"example.test","login":"user@example.test","user":"user"}',
        ];
        yield 'get_filter' => [
            'get_filter',
            static fn (Email $email): mixed => $email->getFilter('user@example.test', 'coffee'),
            'GET',
            'account=user%40example.test&filtername=coffee',
            '{"actions":[{"action":"save","dest":"/mail","number":1}],"filtername":"coffee","metadata'
            . '":{"transformed":1},"rules":[{"match":"contains","number":1,"opt":"and","part":"$header_'
            . 'subject","val":"coffee"}]}',
        ];
        yield 'get_held_message_count' => [
            'get_held_message_count',
            static fn (Email $email): mixed => $email->getHeldMessageCount(),
            'GET',
            '',
            '0',
        ];
        yield 'get_lists_total_disk_usage' => [
            'get_lists_total_disk_usage',
            static fn (Email $email): mixed => $email->getListsTotalDiskUsage(),
            'GET',
            '',
            '0',
        ];
        yield 'get_mailbox_autocreate' => [
            'get_mailbox_autocreate',
            static fn (Email $email): mixed => $email->getMailboxAutocreate('user@example.test'),
            'GET',
            'email=user%40example.test',
            '0',
        ];
        yield 'get_mailman_delegates' => [
            'get_mailman_delegates',
            static fn (Email $email): mixed => $email->getMailmanDelegates('news'),
            'GET',
            'list=news',
            '{"delegates":[],"metadata":{"transformed":1}}',
        ];
        yield 'get_main_account_disk_usage' => [
            'get_main_account_disk_usage',
            static fn (Email $email): mixed => $email->getMainAccountDiskUsage(),
            'GET',
            '',
            '"1.5 MB"',
        ];
        yield 'get_main_account_disk_usage_bytes' => [
            'get_main_account_disk_usage_bytes',
            static fn (Email $email): mixed => $email->getMainAccountDiskUsageBytes(),
            'GET',
            '',
            '1024',
        ];
        yield 'get_max_email_quota' => [
            'get_max_email_quota',
            static fn (Email $email): mixed => $email->getMaxEmailQuota(),
            'GET',
            '',
            '0',
        ];
        yield 'get_max_email_quota_mib' => [
            'get_max_email_quota_mib',
            static fn (Email $email): mixed => $email->getMaxEmailQuotaMib(),
            'GET',
            '',
            '0',
        ];
        yield 'get_pop_quota' => [
            'get_pop_quota',
            static fn (Email $email): mixed => $email->getPopQuota('user'),
            'GET',
            'email=user',
            '0',
        ];
        yield 'get_spam_settings' => [
            'get_spam_settings',
            static fn (Email $email): mixed => $email->getSpamSettings(),
            'GET',
            '',
            '{"cpuser_spam_auto_delete":null,"cpuser_spam_auto_delete_score":null,"rewrites_subjects"'
            . ':0,"spam_as_acl":1,"spam_auto_delete":null,"spam_auto_delete_score":null,"spam_box_enabl'
            . 'ed":0,"spam_enabled":1,"spam_status_changeable":1}',
        ];
        yield 'get_webmail_settings' => [
            'get_webmail_settings',
            static fn (Email $email): mixed => $email->getWebmailSettings(),
            'GET',
            '',
            '{"domain":"example.test","has_maildir":1,"user":"user"}',
        ];
        yield 'has_delegated_mailman_lists' => [
            'has_delegated_mailman_lists',
            static fn (Email $email): mixed => $email->hasDelegatedMailmanLists('user@example.test'),
            'GET',
            'delegate=user%40example.test',
            '0',
        ];
        yield 'has_plaintext_authentication' => [
            'has_plaintext_authentication',
            static fn (Email $email): mixed => $email->hasPlaintextAuthentication(),
            'GET',
            '',
            '0',
        ];
        yield 'hold_outgoing' => [
            'hold_outgoing',
            static fn (Email $email): mixed => $email->holdOutgoing('user@example.test'),
            'POST',
            'email=user%40example.test',
            'null',
        ];
        yield 'list_auto_responders' => [
            'list_auto_responders',
            static fn (Email $email): mixed => $email->listAutoResponders('example.test'),
            'GET',
            'domain=example.test',
            '[{"email":"user@example.test","subject":"Away"}]',
        ];
        yield 'list_default_address' => [
            'list_default_address',
            static fn (Email $email): mixed => $email->listDefaultAddress('user'),
            'GET',
            'user=user',
            '[{"defaultaddress":":fail:","domain":"example.test"}]',
        ];
        yield 'list_domain_forwarders' => [
            'list_domain_forwarders',
            static fn (Email $email): mixed => $email->listDomainForwarders(),
            'GET',
            '',
            '[{"dest":"example.test","forward":"target.test"}]',
        ];
        yield 'list_filters' => [
            'list_filters',
            static fn (Email $email): mixed => $email->listFilters(),
            'GET',
            '',
            '[{"actions":[{"action":"save","dest":"/mail"}],"enabled":1,"filtername":"coffee","rules"'
            . ':[{"match":"contains","opt":"and","part":"$header_subject","val":"coffee"}],"unescaped":'
            . 'false}]',
        ];
        yield 'list_filters_backups' => [
            'list_filters_backups',
            static fn (Email $email): mixed => $email->listFiltersBackups(),
            'GET',
            '',
            '[{"domain":"example.test"}]',
        ];
        yield 'list_forwarders' => [
            'list_forwarders',
            static fn (Email $email): mixed => $email->listForwarders('example.test'),
            'GET',
            'domain=example.test',
            '[{"dest":"user@example.test","forward":"target@example.test","html_dest":"user","html_fo'
            . 'rward":"target","uri_dest":"user","uri_forward":"target"}]',
        ];
        yield 'list_forwarders_backups' => [
            'list_forwarders_backups',
            static fn (Email $email): mixed => $email->listForwardersBackups(),
            'GET',
            '',
            '[{"domain":"example.test"}]',
        ];
        yield 'list_lists' => [
            'list_lists',
            static fn (Email $email): mixed => $email->listLists(),
            'GET',
            '',
            '[{"accesstype":"public","advertised":1,"archive_private":0,"desthost":"example.test","di'
            . 'skused":12,"humandiskused":"12 B","list":"news","listadmin":"owner","listid":"news.examp'
            . 'le.test","subscribe_policy":1}]',
        ];
        yield 'list_mail_domains' => [
            'list_mail_domains',
            static fn (Email $email): mixed => $email->listMailDomains(),
            'GET',
            '',
            '[{"domain":"example.test","select":0}]',
        ];
        yield 'list_mxs' => [
            'list_mxs',
            static fn (Email $email): mixed => $email->listMxs(),
            'GET',
            '',
            '[{"alwaysaccept":1,"detected":"local","domain":"example.test","entries":[{"domain":"exam'
            . 'ple.test","entrycount":1,"mx":"mail.test","priority":5,"row":"row"}],"local":1,"mx":"mai'
            . 'l.test","mxcheck":"local","remote":0,"secondary":0,"status":1,"statusmsg":"ok"}]',
        ];
        yield 'list_pops' => [
            'list_pops',
            static fn (Email $email): mixed => $email->listPops(),
            'GET',
            '',
            '[{"email":"user","login":"user@example.test","suspended_incoming":0,"suspended_login":0}]',
        ];
        yield 'list_pops_with_disk' => [
            'list_pops_with_disk',
            static fn (Email $email): mixed => $email->listPopsWithDisk(),
            'GET',
            '',
            '[{"_diskquota":0,"_diskused":0,"diskquota":"unlimited","diskused":1.5,"diskusedpercent":'
            . '0,"diskusedpercent20":0,"diskusedpercent_float":0.0,"domain":"example.test","email":"use'
            . 'r","has_suspended":0,"hold_outgoing":0,"humandiskquota":"unlimited","humandiskused":"0 B'
            . '","login":"user@example.test","mtime":0,"suspended_incoming":0,"suspended_login":0,"susp'
            . 'ended_outgoing":0,"txtdiskquota":"unlimited","user":"user"}]',
        ];
        yield 'list_system_filter_info' => [
            'list_system_filter_info',
            static fn (Email $email): mixed => $email->listSystemFilterInfo(),
            'GET',
            '',
            '{"filter_info":"Synthetic info"}',
        ];
        yield 'passwd_list' => [
            'passwd_list',
            static fn (Email $email): mixed => $email->passwdList('news', 'secret'),
            'POST',
            'list=news&password=secret',
            'null',
        ];
        yield 'passwd_pop' => [
            'passwd_pop',
            static fn (Email $email): mixed => $email->passwdPop('user', 'secret'),
            'POST',
            'email=user&password=secret',
            'null',
        ];
        yield 'release_outgoing' => [
            'release_outgoing',
            static fn (Email $email): mixed => $email->releaseOutgoing('user@example.test'),
            'POST',
            'email=user%40example.test',
            'null',
        ];
        yield 'remove_mailman_delegates' => [
            'remove_mailman_delegates',
            static fn (Email $email): mixed => $email->removeMailmanDelegates('one@example.test', 'news'),
            'POST',
            'delegates=one%40example.test&list=news',
            '{"delegates":[],"metadata":{"transformed":1}}',
        ];
        yield 'reorder_filters' => [
            'reorder_filters',
            static fn (Email $email): mixed => $email->reorderFilters(['coffee', 'tea'], 'user@example.test'),
            'POST',
            'filter1=coffee&filter2=tea&mailbox=user%40example.test',
            'null',
        ];
        yield 'set_always_accept' => [
            'set_always_accept',
            static fn (Email $email): mixed => $email->setAlwaysAccept('example.test'),
            'POST',
            'domain=example.test',
            '{"checkmx":{"warnings":[]},"detected":"local","local":1,"mxcheck":"local","remote":0,"re'
            . 'sults":"ok","secondary":0,"status":1,"statusmsg":"ok"}',
        ];
        yield 'set_default_address' => [
            'set_default_address',
            static fn (Email $email): mixed => $email->setDefaultAddress('fail'),
            'POST',
            'fwdopt=fail',
            '[{"dest":":fail:","domain":"example.test"}]',
        ];
        yield 'set_list_privacy_options' => [
            'set_list_privacy_options',
            static fn (Email $email): mixed => $email->setListPrivacyOptions(false, true, 'news', 1),
            'POST',
            'advertised=0&archive_private=1&list=news&subscribe_policy=1',
            'null',
        ];
        yield 'set_manual_mx_redirects' => [
            'set_manual_mx_redirects',
            static fn (Email $email): mixed => $email->setManualMxRedirects('example.test', 'mail.test'),
            'POST',
            'domain=example.test&mx_host=mail.test',
            '{"example.test":"mail.test","unset.test":null}',
        ];
        yield 'stats_db_status' => [
            'stats_db_status',
            static fn (Email $email): mixed => $email->statsDbStatus(),
            'GET',
            '',
            '"ok"',
        ];
        yield 'store_filter' => [
            'store_filter',
            static fn (Email $email): mixed => $email->storeFilter(
                ['save'],
                'coffee',
                ['contains'],
                ['$header_subject'],
                ['coffee'],
            ),
            'POST',
            'action1=save&filtername=coffee&match1=contains&part1=%24header_subject&val1=coffee',
            '{"account":"user@example.test"}',
        ];
        yield 'suspend_incoming' => [
            'suspend_incoming',
            static fn (Email $email): mixed => $email->suspendIncoming(),
            'POST',
            '',
            'null',
        ];
        yield 'suspend_login' => [
            'suspend_login',
            static fn (Email $email): mixed => $email->suspendLogin('user@example.test'),
            'POST',
            'email=user%40example.test',
            'null',
        ];
        yield 'suspend_outgoing' => [
            'suspend_outgoing',
            static fn (Email $email): mixed => $email->suspendOutgoing('user@example.test'),
            'POST',
            'email=user%40example.test',
            '1',
        ];
        yield 'terminate_mailbox_sessions' => [
            'terminate_mailbox_sessions',
            static function (Email $email): mixed {
                $email->terminateMailboxSessions();

                return null;
            },
            'POST',
            '',
            null,
        ];
        yield 'trace_delivery' => [
            'trace_delivery',
            static fn (Email $email): mixed => $email->traceDelivery('user@example.test'),
            'GET',
            'recipient=user%40example.test',
            '{"address":"user@example.test","destinations":{"mailbox":"user@example.test","type":"loc'
            . 'al_delivery"},"type":"local_delivery"}',
        ];
        yield 'trace_filter' => [
            'trace_filter',
            static fn (Email $email): mixed => $email->traceFilter('Subject: coffee'),
            'POST',
            'msg=Subject%3A%20coffee',
            '{"trace":"Synthetic trace"}',
        ];
        yield 'unset_manual_mx_redirects' => [
            'unset_manual_mx_redirects',
            static fn (Email $email): mixed => $email->unsetManualMxRedirects('example.test'),
            'POST',
            'domain=example.test',
            '{"example.test":null}',
        ];
        yield 'unsuspend_incoming' => [
            'unsuspend_incoming',
            static fn (Email $email): mixed => $email->unsuspendIncoming(),
            'POST',
            '',
            '1',
        ];
        yield 'unsuspend_login' => [
            'unsuspend_login',
            static fn (Email $email): mixed => $email->unsuspendLogin(),
            'POST',
            '',
            'null',
        ];
        yield 'unsuspend_outgoing' => [
            'unsuspend_outgoing',
            static fn (Email $email): mixed => $email->unsuspendOutgoing('user@example.test'),
            'POST',
            'email=user%40example.test',
            'null',
        ];
        yield 'verify_password' => [
            'verify_password',
            static fn (Email $email): mixed => $email->verifyPassword('user@example.test', 'secret'),
            'POST',
            'email=user%40example.test&password=secret',
            '0',
        ];
    }
}
