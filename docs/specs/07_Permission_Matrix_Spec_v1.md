Urban AR Team Adventure Game — Permission Matrix Specification v1

وضعیت: FROZEN AUTHORIZATION BASELINE  
مرجع: Product Blueprint v1 \+ API Contract v1 \+ State Machine v1

1\. اصل سراسری Authorization  
Authorization فقط Server-side معتبر است.  
پنهان‌کردن دکمه در UI هیچ امنیتی ایجاد نمی‌کند.  
هر Action باید حداقل این Scopeها را بررسی کند:  
\- authenticated user  
\- system role  
\- organization scope  
\- resource ownership/scope  
\- game/session assignment  
\- team membership/captaincy  
\- device binding در عملیات حساس  
\- resource state  
\- explicit capability

2\. Roleهای سیستمی

SUPER\_ADMIN  
سطح سراسری پلتفرم.  
می‌تواند Organizationها و تنظیمات کلان را مدیریت کند.  
نباید به‌صورت پیش‌فرض بدون Audit وارد عملیات Session تجاری شود مگر action مجاز و reason ثبت شود.

ORGANIZATION\_ADMIN  
مدیر یک Organization.  
دسترسی فقط داخل همان Organization.  
مدیریت Game، Session، Pricing، Store، GameMaster assignment، content و گزارش‌های Organization.

GAME\_MASTER  
اپراتور اجرای مسابقه.  
Scope باید به Organization/Game/Sessionهای assign‌شده محدود شود.  
قدرت اصلی روی Live Session و Challenge/Recovery است.

PLAYER  
کاربر عادی بازی.  
به هویت، Teamهای خودش، Sessionهای مجاز، Runtime خودش/Team و Store مجاز دسترسی دارد.

CAPTAIN  
Role سیستمی مستقل نیست؛ capability مشتق‌شده از captain\_user\_id در Team است.  
Captain علاوه بر Player permission، مدیریت Team قبل از Start دارد.

3\. اصل Deny-by-Default  
هر permission که صریحاً allow نشده \=\> deny.  
Policy/Gateها منبع Authorization هستند.  
Frontend از capability response برای UX استفاده می‌کند ولی مرجع نهایی Server است.

4\. Organization Permissions

Action: organization.list\_all  
SUPER\_ADMIN: Allow  
ORGANIZATION\_ADMIN: فقط Organization خودش  
GAME\_MASTER: Deny  
PLAYER: Deny

organization.create  
SUPER\_ADMIN: Allow  
Others: Deny

organization.read  
SUPER\_ADMIN: Allow  
ORGANIZATION\_ADMIN: Own org  
GAME\_MASTER: Own assigned org limited  
PLAYER: فقط اطلاعات public/minimal در صورت نیاز

organization.update  
SUPER\_ADMIN: Allow  
ORGANIZATION\_ADMIN: Own org  
Others: Deny

organization.archive  
SUPER\_ADMIN: Allow  
ORGANIZATION\_ADMIN: Own org only if commercial policy allows  
Others: Deny

organization.manage\_members  
SUPER\_ADMIN: Allow  
ORGANIZATION\_ADMIN: Own org  
GAME\_MASTER: Deny  
PLAYER: Deny

5\. Identity Permissions

user.read\_self  
همه authenticated users: Allow

user.update\_self  
همه authenticated users: Allow برای fields مجاز

user.read\_other  
SUPER\_ADMIN: Allow  
ORGANIZATION\_ADMIN: فقط members/users داخل org در حد نیاز  
GAME\_MASTER: فقط session participants در حد operational data  
PLAYER: فقط profile عمومی/محدود اعضای Team طبق privacy policy

user.suspend/block  
SUPER\_ADMIN: Allow  
ORGANIZATION\_ADMIN: فقط org-scoped suspension اگر مدل product اجازه دهد  
GAME\_MASTER: Deny برای account-level; فقط session disqualify  
PLAYER: Deny

6\. Device Permissions

device.list\_self  
PLAYER: Allow own  
Admins: operational support only with scope

device.revoke\_self  
PLAYER: Allow own non-blocked devices

device.bind\_session  
PLAYER: فقط خود و roster/session معتبر

device.rebind\_self  
PLAYER: فقط recovery policy اگر مجاز  
GameMaster: assigned session \+ reason  
OrgAdmin: own org \+ reason  
SuperAdmin: Allow \+ reason

device.view\_sensitive  
GameMaster/OrgAdmin فقط operational fields؛ secret/token data هرگز نمایش داده نشود.

7\. Team Permissions

team.create  
PLAYER: Allow

team.read  
Member: full team profile allowed  
Non-member: public/minimal only if needed

team.update  
Captain: Allow pre-game/non-frozen fields  
OrgAdmin: if org-owned team model applies  
Others: Deny

team.invite.create/revoke  
Captain: Allow  
OrgAdmin/SuperAdmin: support override with audit  
Others: Deny

team.member.remove  
Captain: Allow before blocking session conditions  
Self leave separate  
GameMaster: Deny on persistent team; may affect session participation only

team.transfer\_captaincy  
Current Captain: Allow to active member  
Admin override: exceptional \+ audit

team.archive  
Captain: Allow if no blocking active session  
Admin: support override

8\. Game Permissions

game.create  
OrgAdmin: Allow own org  
SuperAdmin: Allow  
GameMaster: Deny by default  
Player: Deny

game.read  
OrgAdmin/GameMaster assigned: Allow  
Player: active/public playable game info only

game.update  
OrgAdmin: Allow  
SuperAdmin: Allow  
GameMaster: Deny unless explicit content editor capability

game.archive  
OrgAdmin/SuperAdmin only

game.manage\_pricing  
OrgAdmin/SuperAdmin only

9\. Game Session Permissions

session.create  
OrgAdmin: Allow  
GameMaster: Allow فقط اگر organization policy enable کند  
Player: Deny

session.read  
OrgAdmin: own org  
GameMaster: assigned session  
Player: sessions registered/available to player

session.update\_prestart  
OrgAdmin  
Assigned GameMaster  
state guards apply

session.open\_registration  
OrgAdmin / assigned GameMaster

session.register\_team  
Captain: Allow for own team  
OrgAdmin/GameMaster: operational override with audit

session.remove\_team\_prestart  
Captain: own team if policy permits  
GameMaster/OrgAdmin: Allow with reason

session.start  
Assigned GameMaster  
OrgAdmin  
SuperAdmin exceptional  
Player/Captain: Deny

session.pause/resume  
Assigned GameMaster  
OrgAdmin  
SuperAdmin exceptional

session.finish  
Assigned GameMaster / OrgAdmin

session.cancel\_prestart  
OrgAdmin / assigned GameMaster with reason

session.emergency\_cancel\_poststart  
GameMaster if emergency capability assigned  
OrgAdmin  
SuperAdmin  
Reason mandatory

10\. Session Player Permissions

player.ready  
Player: self only  
Device bound required

player.reconnect  
Player: self only

player.late\_join  
Player: self only if frozen roster member and guard passes

player.remove\_prestart  
Captain own roster within policy  
GameMaster/OrgAdmin operational

player.disqualify  
GameMaster assigned session  
OrgAdmin  
SuperAdmin  
Reason mandatory

11\. Scenario Permissions

scenario.create  
OrgAdmin  
Content Editor capability if introduced  
SuperAdmin

scenario.read\_draft  
OrgAdmin  
Authorized GameMaster/Content editor only

scenario.read\_published  
Assigned GameMaster  
Player only through runtime-scoped content, not full scenario source

scenario.update\_draft  
OrgAdmin/content editor

scenario.publish  
OrgAdmin or explicit Publisher capability  
GameMaster by default Deny

scenario.archive  
OrgAdmin

scenario.validate/simulate  
OrgAdmin/content editor

Player must never retrieve hidden answers, condition internals, secret variables or future nodes.

12\. Scenario Assignment Permissions

assignment.read  
GameMaster assigned  
OrgAdmin  
Team/Player only result necessary for runtime, not full alternative list

assignment.create/update  
GameMaster assigned pre-start  
OrgAdmin  
Automatic engine system actor

Player/Captain: Deny

13\. Runtime Permissions

runtime.read\_self  
Player: own session/team scoped runtime

runtime.read\_team  
Team member: team-safe view  
GameMaster: full operational view

runtime.start\_node  
Eligible Player/role only

runtime.submit\_node  
Eligible Player/role only  
Device bound required

runtime.complete\_node  
Generally engine/server handler  
Client submits evidence, not final authoritative completion

runtime.skip\_node  
GameMaster/OrgAdmin only  
Reason mandatory

runtime.force\_transition  
No generic public permission.  
Exceptional internal/admin repair only with dedicated audited action.

14\. GPS/Location Permissions

location.submit  
Player self  
active session  
device bound  
valid participation

location.read\_self  
Player self

location.read\_team  
Player: only if Game/Scenario policy allows  
Default: teammates can receive only necessary operational location

location.read\_competitor  
Default Deny

location.read\_all\_session  
Assigned GameMaster  
OrgAdmin  
SuperAdmin exceptional

raw\_location\_export  
OrgAdmin/authorized support only  
reason \+ retention/privacy policy

15\. Checkpoint Permissions

checkpoint.create/update  
OrgAdmin/content editor  
GameMaster only if explicit route-editor capability

checkpoint.read\_runtime  
Player only active/revealed checkpoint data

hidden checkpoint list/source  
Player: Deny

16\. QR Permissions

qr.scan  
Player self, device bound

qr.manage  
OrgAdmin/content editor

qr.view\_raw\_identifiers  
Admins/content ops only  
Player: Deny

qr.override  
GameMaster exceptional and audited if supported

17\. AR Permissions

ar.start/complete  
Eligible Player  
active node/challenge  
device bound

ar.manage\_scene\_metadata  
OrgAdmin/content editor

ar.asset\_publish  
Authorized publisher/content ops

GameMaster may trigger/assist but cannot fabricate completion without explicit audited recovery action.

18\. Live Challenge Permissions

challenge.create  
Assigned GameMaster  
OrgAdmin

challenge.target\_player/team/session  
Assigned GameMaster within session

challenge.cancel  
Creator GameMaster or higher scoped admin

challenge.submit  
Target Player(s) only

challenge.manual\_complete  
GameMaster only if challenge policy allows \+ reason

19\. Hint Permissions

hint.consume  
Any eligible Team Player by default  
Game config may restrict to active slots

hint.purchase  
Payer policy determines Captain/Player/Organizer  
Server validates financial scope

hint.grant\_free  
GameMaster only if session policy permits \+ audit  
OrgAdmin

hint.reset\_usage  
Deny generic.  
Only compensating repair action with Admin.

20\. Score Permissions

score.read\_team  
Team members

score.read\_leaderboard  
According to visibility policy

score.adjust  
GameMaster/OrgAdmin  
reason mandatory  
delta only; no direct set

score.finalize  
System/session finish process  
GameMaster triggers finish, engine finalizes

Player: never direct mutation.

21\. Billing Permissions

billing.read\_session  
Captain/payer: own team/order summary  
Player: own participation/payment  
GameMaster: operational payment-ready status, not unnecessary payment detail  
OrgAdmin: own org full commercial view  
SuperAdmin: scoped support

order.create  
Payer allowed by session policy

order.read  
Payer/authorized captain/admin

payment.initiate  
Authorized payer

payment.verify  
System/provider/backend only  
User may trigger verify endpoint if contract allows, but cannot provide success truth.

refund.request  
Payer may request if policy supports  
Execution: OrgAdmin/authorized commercial role

refund.execute  
OrgAdmin/finance capability  
GameMaster default Deny

22\. Store Permissions

store\_product.read  
Players for active products  
Admins for all states

store\_product.create/update/activate/archive  
OrgAdmin/commercial content role

purchase.create  
Authorized Player/Captain/Payer

purchase.read  
Buyer/team scope \+ admins

entitlement.read  
Owner/team members where relevant

entitlement.consume  
Domain action only through specific feature, e.g. Hint consume  
No generic client entitlement decrement endpoint.

entitlement.revoke  
OrgAdmin/fraud/admin process only

23\. Anti-Cheat Permissions

anti\_cheat.flag.read  
GameMaster assigned session  
OrgAdmin  
SuperAdmin

Player: Deny raw flag details by default

anti\_cheat.review  
GameMaster/OrgAdmin

anti\_cheat.dismiss  
GameMaster/OrgAdmin with reason

anti\_cheat.enforce  
GameMaster within allowed session action  
OrgAdmin  
High severity account action only higher authority

anti\_cheat.rule.manage  
OrgAdmin/SuperAdmin  
GameMaster Deny

24\. Media Permissions

media.upload  
Authenticated user for allowed user-owned media  
Org content users for organization media

media.read  
based on owning resource visibility

media.archive/delete  
Owner if unreferenced and policy permits  
OrgAdmin for org assets  
Referenced protected media cannot hard delete

25\. Audit Permissions

audit.read\_global  
SuperAdmin only

audit.read\_org  
OrgAdmin own org

audit.read\_session  
Assigned GameMaster limited to operational/session events

Player  
No generic audit endpoint.

audit.modify/delete  
Deny all normal roles.  
Append-only.

26\. Analytics Permissions

analytics.platform  
SuperAdmin

analytics.organization  
OrgAdmin own org

analytics.session\_operational  
Assigned GameMaster

analytics.player\_personal  
Player only own stats if feature exposed

raw PII analytics export  
restricted admin capability only

27\. Realtime Channel Permissions

private-user.{user\_id}  
Only same authenticated user \+ authorized support exceptional

private-session.{session\_id}  
Participant with session membership OR assigned GameMaster/admin  
Payload still role-filtered

private-session.{session\_id}.team.{team\_id}  
Team member in that session  
GameMaster/admin

private-session.{session\_id}.player.{player\_id}  
same player  
authorized GameMaster/admin

private-gamemaster.{session\_id}  
assigned GameMaster  
OrgAdmin  
SuperAdmin

private-organization.{organization\_id}.admins  
OrgAdmin  
SuperAdmin

28\. Sensitive Action Reason Requirement

Reason mandatory for:  
device rebind by staff  
player/team disqualification  
post-start cancellation  
manual score adjustment  
node skip  
financial override  
refund execution where manual  
anti-cheat enforcement/dismiss  
data export containing sensitive/raw location  
account status change  
emergency action

29\. Step-Up / Elevated Authorization  
برای عملیات بسیار حساس می‌توان بعداً step-up auth اضافه کرد:  
large refund  
organization ownership change  
payment provider config  
mass export  
SuperAdmin impersonation/support  
bulk disqualification

Architecture باید اجازه اضافه‌شدن بدون تغییر Domain بدهد.

30\. System Actor Permissions  
SYSTEM actor فقط از Serviceهای داخلی trusted می‌آید.  
Examples:  
automatic scenario assignment  
timer expiry  
outbox publish  
score projection  
payment webhook verify  
entitlement expiry  
geofence evaluation

Client cannot claim actor\_type=SYSTEM.

31\. Support/Impersonation Policy  
No silent impersonation.  
اگر support impersonation در آینده اضافه شود:  
explicit start/end session  
banner/UI indication  
reason/ticket required  
audit every mutation  
financial/security actions may remain blocked

32\. Permission Response to Clients  
API می‌تواند capabilities برگرداند:  
{  
  "capabilities": {  
    "can\_start\_session": true,  
    "can\_adjust\_score": false  
  }  
}

اما Client capability cache advisory است.  
Server دوباره هر request را authorize می‌کند.

33\. Authorization Error Contract

Unauthenticated:  
401 AUTHENTICATION\_REQUIRED

Authenticated but unauthorized:  
403 AUTHORIZATION\_DENIED

Cross-tenant resource:  
prefer 404 RESOURCE\_NOT\_FOUND where disclosing existence is sensitive.

Invalid state but user otherwise authorized:  
409 STATE\_TRANSITION\_INVALID

Device mismatch:  
409/403 based API contract, canonical domain code DEVICE\_NOT\_BOUND.

34\. Policy Implementation Guidance for Laravel  
Policies/Gates per aggregate:  
OrganizationPolicy  
TeamPolicy  
GamePolicy  
GameSessionPolicy  
ScenarioPolicy  
RuntimePolicy  
BillingPolicy  
StorePolicy  
MediaPolicy  
AntiCheatPolicy  
AuditPolicy

Shared scope services:  
OrganizationScope  
SessionAssignmentScope  
TeamMembershipScope  
DeviceBindingGuard

Controllers thin.  
No scattered role string checks across controllers.

35\. Permission Testing Requirements  
هر sensitive action حداقل tests:  
allowed role \+ correct scope \=\> pass  
same role \+ wrong organization \=\> deny  
same role \+ unassigned session \=\> deny  
Player attempts admin action \=\> deny  
Captain attempts another team \=\> deny  
GameMaster attempts unassigned session \=\> deny  
cross-tenant ID guessing \=\> deny/not found  
revoked device sensitive action \=\> deny  
terminal state mutation \=\> conflict  
admin override without reason \=\> deny/validation fail

36\. High-Risk Negative Tests  
Player cannot fetch scenario answers.  
Player cannot set score.  
Player cannot verify own payment as successful.  
Player cannot decrement entitlement generically.  
GameMaster cannot refund by default.  
GameMaster cannot manage another org.  
Captain cannot remove player after frozen roster via persistent team action to affect runtime.  
Client cannot subscribe competitor private channel.  
User cannot bind another user's device.  
OrgAdmin cannot cross tenant.

37\. Definition of Done  
Role hierarchy and scope frozen.  
Captain modeled as derived capability, not global role.  
Organization isolation mandatory.  
Session assignment enforced.  
Resource state and device binding integrated with permission checks.  
Sensitive actions reason-audited.  
Realtime channel authorization defined.  
Negative authorization tests mandatory.  
Codex must not add new privileged action without Permission Matrix update or approved revision.  
