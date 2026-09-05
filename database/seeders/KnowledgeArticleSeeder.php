<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

/**
 * What the assistant knows. Everything it tells anyone comes from these rows,
 * so the wording is the answer a person reads, not a note to an editor.
 */
class KnowledgeArticleSeeder extends Seeder
{
    /** @var list<array{category: string|null, title: string, keywords: string, body: string}> */
    private const ARTICLES = [
        ['category' => null, 'title' => 'How the helpdesk works', 'keywords' => 'ticket, status, moderator, process, how it works',
            'body' => 'Raise a ticket by describing the problem to the assistant. A moderator picks it up from the shared queue, replies on the ticket, and moves it through open, in progress and resolved. You get an email whenever the status changes or someone picks it up. Closed tickets take no further replies.'],
        ['category' => null, 'title' => 'What the priorities mean', 'keywords' => 'priority, urgent, high, medium, low, deadline, sla',
            'body' => 'Urgent means you cannot work at all or there is a security risk, and the target for a first response is 4 hours. High means you are blocked on a deadline, and the target is 24 hours. Medium means something is degraded but you can still work, and the target is 48 hours. Low covers cosmetic issues and questions, with a target of 72 hours. The assistant sets the priority from what you describe, and a moderator can change it.'],
        ['category' => null, 'title' => 'Support hours and reaching a person', 'keywords' => 'hours, human, contact, when, open',
            'body' => 'The desk is staffed Sunday to Thursday, 8:00 to 17:00. Outside those hours tickets are still accepted and picked up on the next working day. If you would rather speak to a person than the assistant, ask it to raise a ticket and a moderator will reply on it.'],
        ['category' => null, 'title' => 'Following a ticket you raised', 'keywords' => 'follow, track, my tickets, progress, reply',
            'body' => 'Your tickets are listed under Your tickets, with a progress track showing whether each one is submitted, in progress or resolved. Open a ticket to read the replies and add your own. You are emailed when the status changes, not for every message.'],
        ['category' => null, 'title' => 'Who can see your ticket', 'keywords' => 'privacy, visible, who sees, confidential',
            'body' => 'You can see your own tickets. Moderators can see every ticket, because they work one shared queue. Administrators can see every ticket as well as accounts and reporting. Nobody outside the organisation has access. Do not put passwords or card numbers in a ticket.'],

        ['category' => 'it-access', 'title' => 'VPN will not connect from home', 'keywords' => 'vpn, remote, connect, home, tunnel',
            'body' => 'Check that you are on an ordinary home connection rather than a hotel or public network, since many of those block VPN traffic. Quit the VPN client completely and open it again. If it asks for a second factor, approve it within 30 seconds. An authentication error usually means your password has expired, so sign in to the intranet in a browser first. A timeout usually means the network, so restart your router. If none of that helps, raise a ticket and say which error you see.'],
        ['category' => 'it-access', 'title' => 'VPN drops every few minutes', 'keywords' => 'vpn, drops, disconnect, unstable, wifi',
            'body' => 'Frequent drops are almost always the local network rather than the VPN. Move closer to the router or use a cable, and check whether other devices drop at the same time. Turn off any second VPN or proxy. If the drops continue on a stable connection, raise a ticket with the times it happened so the gateway logs can be matched to them.'],
        ['category' => 'it-access', 'title' => 'Locked out after too many password attempts', 'keywords' => 'locked, lockout, password, attempts, blocked',
            'body' => 'Accounts lock for 15 minutes after five wrong attempts. Wait the 15 minutes and try once more carefully. If you have forgotten the password, use the Forgotten it link on the sign-in page to get a reset link by email. If you no longer have access to that mailbox, raise a ticket and an administrator will send a link another way.'],
        ['category' => 'it-access', 'title' => 'Resetting your password', 'keywords' => 'password, reset, forgot, change, link',
            'body' => 'Use the Forgotten it link on the sign-in page and enter your work email address. The link that arrives works once and expires after 60 minutes, and asking for a new link cancels the previous one. An administrator can also send you a link, but nobody can set a password on your behalf. Changing your password signs you out of every other device.'],
        ['category' => 'it-access', 'title' => 'Setting up multi-factor authentication', 'keywords' => 'mfa, 2fa, authenticator, second factor, phone',
            'body' => 'Install an authenticator app on your phone, then scan the code shown in your account settings on the intranet. Keep the recovery codes somewhere safe and offline. If you change phone, set up the new one before wiping the old one. If you are locked out with no recovery codes, raise a ticket and expect an identity check before it is reset.'],
        ['category' => 'it-access', 'title' => 'Access to a shared drive or folder', 'keywords' => 'shared drive, folder, permission, access, files',
            'body' => 'Access to a shared folder is granted by its owner, not by IT alone. Raise a ticket naming the exact folder path and what you need to do with it, reading or editing, and say who asked you to work on it. Requests without an owner to confirm them sit waiting, so include that name.'],
        ['category' => 'it-access', 'title' => 'Email on your phone', 'keywords' => 'email, phone, mobile, mail app, sync',
            'body' => 'Use the official mail app and sign in with your work address and password, then approve the second factor. If it refuses repeatedly, remove the account from the phone and add it again rather than retyping the password. Personal mail apps that store your password are not permitted on work accounts.'],
        ['category' => 'it-access', 'title' => 'A new starter needs an account', 'keywords' => 'new starter, account, onboarding, joiner, create',
            'body' => 'Accounts are created by an administrator, not by self-registration. Raise a ticket at least three working days before the start date with the person full name, their role, their manager and the date they start. Say which systems they need beyond email, since access is granted per system.'],

        ['category' => 'it-hardware', 'title' => 'Laptop fan running loudly', 'keywords' => 'fan, noise, hot, overheating, laptop',
            'body' => 'A loud fan usually means something is using the processor heavily. Close applications you are not using, especially browsers with many tabs and video calls running in the background. Restart the laptop at least once a day. Keep the vents clear and avoid using it on a bed or sofa. If it is loud even when idle for more than a day, raise a ticket so the hardware can be checked.'],
        ['category' => 'it-hardware', 'title' => 'Laptop battery does not charge', 'keywords' => 'battery, charging, power, charger, dead',
            'body' => 'Try a different socket and, if you have one, a different charger of the same type. Check the cable where it meets the connector for damage. Some laptops stop charging at 80 percent on purpose to protect the battery, which is not a fault. If the battery still does not charge on a known good charger, raise a ticket and mention how old the laptop is.'],
        ['category' => 'it-hardware', 'title' => 'External monitor is not detected', 'keywords' => 'monitor, screen, display, hdmi, docking',
            'body' => 'Unplug the cable at both ends and plug it back in, then use the display shortcut on your keyboard to cycle the output. If you use a dock, unplug the dock from power for ten seconds. Try a different cable before assuming the monitor has failed. If the monitor works on another machine but not yours, raise a ticket and say which dock and cable you used.'],
        ['category' => 'it-hardware', 'title' => 'Docking station stops working', 'keywords' => 'dock, docking, usb-c, port, hub',
            'body' => 'Docks usually recover from a power cycle: unplug the dock from the wall, wait ten seconds and plug it back in before reconnecting the laptop. Connect the laptop directly to the charger to confirm it is charging. If only some ports fail, note which ones in a ticket, since that tells us whether it is the dock or the cable.'],
        ['category' => 'it-hardware', 'title' => 'Printing problems', 'keywords' => 'printer, print, paper, queue, spooler',
            'body' => 'Check that the printer is on and has paper, then clear anything stuck in the print queue on your machine and try one page. Confirm you are sending to the printer you are standing next to, as the default is often another floor. If pages come out blank or streaked, raise a ticket with the printer name from the label on the front.'],
        ['category' => 'it-hardware', 'title' => 'Requesting a replacement laptop', 'keywords' => 'new laptop, replacement, upgrade, request, hardware',
            'body' => 'Laptops are replaced on a four year cycle or earlier when they fail. Raise a ticket with the age of your current machine, what it struggles with, and any deadline you are working towards. Expect a short conversation about specification before anything is ordered, and allow two weeks for delivery and setup.'],
        ['category' => 'it-hardware', 'title' => 'Headset or microphone not working in calls', 'keywords' => 'headset, microphone, audio, sound, calls',
            'body' => 'Check that the correct device is selected as both input and output in the call application settings, since connecting a headset does not always switch it. Unplug and reconnect the headset while no call is running. Test with the operating system sound settings to see whether the problem is the headset or the application. If only one application is affected, say which one in the ticket.'],
        ['category' => 'it-hardware', 'title' => 'Laptop is running slowly', 'keywords' => 'slow, performance, freezing, lag, memory',
            'body' => 'Restart the machine, since uptime measured in weeks is the most common cause. Close applications you are not using and check whether an update is installing in the background. If it is slow only in one application, say which one. If it has been slow for more than a few days after a restart, raise a ticket and mention when it started.'],

        ['category' => 'hr-payroll', 'title' => 'When you get paid', 'keywords' => 'payday, salary, when, paid, date',
            'body' => 'Salaries are paid on the 25th of each month, or the last working day before it when the 25th falls on a weekend or a holiday. Payslips are available two working days before payday. If the payment has not arrived by the end of payday, raise a ticket rather than waiting, since a failed transfer is fixed in the following batch.'],
        ['category' => 'hr-payroll', 'title' => 'Payslip is missing', 'keywords' => 'payslip, missing, download, document, pay',
            'body' => 'Payslips appear in the payroll portal two working days before payday and stay there for seven years. If the current month is missing, check that you are looking at the right tax year, since the portal opens on the previous one after April. If it is genuinely absent, raise a ticket naming the month.'],
        ['category' => 'hr-payroll', 'title' => 'Overtime is missing from my pay', 'keywords' => 'overtime, hours, missing, unpaid, extra',
            'body' => 'Overtime is paid in the month after it is approved, so hours worked late in a month usually appear on the following payslip. Check that your manager approved the hours rather than only seeing them. If approved hours are still missing after two payslips, raise a ticket listing the dates and the number of hours.'],
        ['category' => 'hr-payroll', 'title' => 'Leave balance looks wrong', 'keywords' => 'leave, holiday, balance, annual leave, days',
            'body' => 'The balance shown includes booked but not yet taken days, which is why it often looks lower than expected. Carried over days from last year expire at the end of March. Unpaid and parental leave are tracked separately and do not appear in the annual figure. If the balance is still wrong after allowing for those, raise a ticket with the number of days you expected and how you calculated it.'],
        ['category' => 'hr-payroll', 'title' => 'Claiming expenses', 'keywords' => 'expenses, claim, receipt, reimbursement, travel',
            'body' => 'Submit expenses in the finance portal within 60 days of the spend, with a readable receipt attached to each line. Claims approved before the 15th are paid with that month salary, and later ones the month after. Travel booked outside the approved supplier needs a note explaining why, or it is returned unpaid.'],
        ['category' => 'hr-payroll', 'title' => 'Expense reimbursement has not arrived', 'keywords' => 'expenses, reimbursement, not paid, delayed, claim',
            'body' => 'Reimbursements are paid with salary, not separately, so check the payslip before assuming it is missing. Claims approved after the 15th move to the following month. If your manager approved the claim before the cut-off and it does not appear on that payslip, raise a ticket with the claim number.'],
        ['category' => 'hr-payroll', 'title' => 'Changing your bank details', 'keywords' => 'bank, account, iban, change details, salary',
            'body' => 'Bank details are changed in the payroll portal, and the change must be saved before the 10th to affect that month salary. You are asked to confirm the change by email, and payroll will telephone you to verify it. Nobody from payroll will ever ask for your bank details in a ticket or a chat message.'],
        ['category' => 'hr-payroll', 'title' => 'Requesting an employment letter', 'keywords' => 'letter, employment, proof, visa, mortgage',
            'body' => 'Raise a ticket saying who the letter is addressed to, what it must confirm, such as salary or start date, and the date you need it by. Standard letters take three working days. Letters for a visa or a mortgage often have a required wording, so attach any template the recipient gave you.'],
    ];

    public function run(): void
    {
        $categories = Category::pluck('id', 'slug');

        foreach (self::ARTICLES as $article) {
            KnowledgeArticle::updateOrCreate(
                ['title' => $article['title']],
                [
                    'body' => $article['body'],
                    'keywords' => $article['keywords'],
                    'category_id' => $article['category'] === null ? null : $categories[$article['category']],
                    'is_active' => true,
                ],
            );
        }
    }
}
