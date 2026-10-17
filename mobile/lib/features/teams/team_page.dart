import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_routes.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import 'join_team_page.dart';
import 'team_repository.dart';

class TeamPage extends StatefulWidget {
  const TeamPage({required this.auth, this.repository, super.key});

  final AuthController auth;
  final TeamRepository? repository;

  @override
  State<TeamPage> createState() => _TeamPageState();
}

class _TeamPageState extends State<TeamPage> {
  late final TeamRepository repository;
  late Future<List<NexoTeam>> teams;
  bool busy = false;

  @override
  void initState() {
    super.initState();
    repository = widget.repository ?? TeamRepository(widget.auth.api);
    teams = repository.list();
  }

  Future<void> refresh() async {
    setState(() {
      teams = repository.list();
    });
    try {
      await teams;
    } catch (_) {
      // The error state offers a retry action.
    }
  }

  Future<void> run(Future<void> Function() action) async {
    if (busy) return;
    setState(() => busy = true);
    try {
      await action();
      if (mounted) {
        setState(() {
          teams = repository.list();
        });
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(teamError(error))),
        );
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> createTeam() async {
    final name = await showDialog<String>(
      context: context,
      builder: (_) => const _TeamNameDialog(),
    );
    if (name == null || name.trim().isEmpty) return;
    await run(() async {
      await repository.create(name);
    });
  }

  Future<void> joinTeam() async {
    final joined = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(builder: (_) => JoinTeamPage(auth: widget.auth)),
    );
    if (joined == true && mounted) {
      setState(() {
        teams = repository.list();
      });
    }
  }

  Future<void> makeInvite(NexoTeam team) async {
    if (busy) return;
    setState(() => busy = true);
    try {
      final result = await repository.createInvite(team.id);
      final code = result['invite_code'] as String?;
      if (code == null || !mounted) return;
      await showDialog<void>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: const Text('کد دعوت تیم'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                  'این کد را فقط با افرادی که می‌خواهید دعوت کنید به‌اشتراک بگذارید.'),
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: NexoTheme.background,
                  borderRadius: BorderRadius.circular(NexoTheme.radiusSmall),
                  border: Border.all(color: NexoTheme.border),
                ),
                child: SelectableText(
                  code,
                  textDirection: TextDirection.ltr,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 1.1),
                ),
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: () async {
                  await Clipboard.setData(ClipboardData(text: code));
                  if (dialogContext.mounted) {
                    ScaffoldMessenger.of(dialogContext).showSnackBar(
                      const SnackBar(content: Text('کد دعوت کپی شد.')),
                    );
                  }
                },
                icon: const Icon(Icons.copy_outlined),
                label: const Text('کپی کد'),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('بستن'),
            ),
          ],
        ),
      );
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(teamError(error))));
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> transferCaptaincy(NexoTeam team) async {
    final currentUserId = widget.auth.user?['id'] as String? ?? '';
    final candidates = team.members
        .where((member) => member.userId != currentUserId)
        .toList(growable: false);
    if (candidates.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('عضو دیگری برای انتقال کاپیتانی وجود ندارد.')),
      );
      return;
    }
    final target = await showDialog<TeamMember>(
      context: context,
      builder: (context) => SimpleDialog(
        title: const Text('انتقال کاپیتانی به'),
        children: [
          for (final candidate in candidates)
            SimpleDialogOption(
              onPressed: () => Navigator.pop(context, candidate),
              child: Text(candidate.name),
            ),
        ],
      ),
    );
    if (target == null || !mounted) return;
    final confirmed = await confirm(
      'کاپیتانی تیم به ${target.name} منتقل شود؟',
      action: 'انتقال',
    );
    if (confirmed) {
      await run(() => repository.transferCaptaincy(team.id, target.userId));
    }
  }

  Future<bool> confirm(String message, {required String action}) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('تأیید عملیات'),
          content: Text(message),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('انصراف'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: Text(action),
            ),
          ],
        ),
      ) ??
      false;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('تیم من'),
          leading: IconButton(
            tooltip: 'بازگشت به خانه',
            icon: const Icon(Icons.arrow_back),
            onPressed: () => Navigator.of(context)
                .pushNamedAndRemoveUntil(NexoRoutes.home, (_) => false),
          ),
        ),
        body: Directionality(
          textDirection: TextDirection.rtl,
          child: SafeArea(
            child: FutureBuilder<List<NexoTeam>>(
              future: teams,
              builder: (context, snapshot) {
                if (snapshot.connectionState != ConnectionState.done) {
                  return const NexoLoading(message: 'در حال دریافت تیم‌ها…');
                }
                if (snapshot.hasError) {
                  final offline = ApiClient.isNetworkFailure(snapshot.error!);
                  return NexoErrorView(
                    message: offline
                        ? 'اتصال اینترنت برقرار نیست. پس از اتصال دوباره تلاش کنید.'
                        : teamError(snapshot.error!),
                    offline: offline,
                    onRetry: refresh,
                  );
                }

                final items = snapshot.data ?? const <NexoTeam>[];
                return RefreshIndicator(
                  onRefresh: refresh,
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
                    children: [
                      if (items.isEmpty) ...[
                        const _TeamEmptyState(),
                        const SizedBox(height: 16),
                        FilledButton.icon(
                          onPressed: busy ? null : createTeam,
                          icon: const Icon(Icons.add_moderator_outlined),
                          label: const Text('ساخت تیم'),
                        ),
                        const SizedBox(height: 8),
                        OutlinedButton.icon(
                          onPressed: busy ? null : joinTeam,
                          icon: const Icon(Icons.key_outlined),
                          label: const Text('ورود با کد دعوت'),
                        ),
                      ],
                      for (final team in items) ...[
                        _TeamIdentityCard(team: team),
                        const SizedBox(height: 12),
                        _TeamReadiness(team: team),
                        const SizedBox(height: 20),
                        Text('اعضای تیم',
                            style: Theme.of(context).textTheme.titleLarge),
                        const SizedBox(height: 8),
                        for (final member in team.members)
                          _MemberTile(
                            member: member,
                            isCaptain: member.userId == team.captainUserId,
                            canRemove: team.captainUserId ==
                                    (widget.auth.user?['id'] as String? ??
                                        '') &&
                                member.userId !=
                                    (widget.auth.user?['id'] as String? ??
                                        '') &&
                                team.status == 'ACTIVE',
                            busy: busy,
                            onRemove: () async {
                              final confirmed = await confirm(
                                'عضویت ${member.name} از این تیم حذف شود؟',
                                action: 'حذف عضو',
                              );
                              if (confirmed) {
                                await run(() =>
                                    repository.remove(team.id, member.userId));
                              }
                            },
                          ),
                        const SizedBox(height: 8),
                        if (team.captainUserId ==
                                (widget.auth.user?['id'] as String? ?? '') &&
                            team.status == 'ACTIVE')
                          _CaptainActions(
                            busy: busy,
                            onInvite: () => makeInvite(team),
                            onTransfer: () => transferCaptaincy(team),
                          )
                        else if (team.captainUserId !=
                                (widget.auth.user?['id'] as String? ?? '') &&
                            team.status == 'ACTIVE')
                          OutlinedButton.icon(
                            onPressed: busy
                                ? null
                                : () async {
                                    final confirmed = await confirm(
                                      'از تیم «${team.name}» خارج شوید؟',
                                      action: 'خروج از تیم',
                                    );
                                    if (confirmed) {
                                      await run(
                                          () => repository.leave(team.id));
                                    }
                                  },
                            icon: const Icon(Icons.logout),
                            label: const Text('خروج از تیم'),
                          ),
                        const SizedBox(height: 20),
                      ],
                      if (items.isNotEmpty) ...[
                        OutlinedButton.icon(
                          onPressed: busy ? null : createTeam,
                          icon: const Icon(Icons.group_add_outlined),
                          label: const Text('ساخت تیم دیگر'),
                        ),
                        const SizedBox(height: 8),
                        OutlinedButton.icon(
                          onPressed: busy ? null : joinTeam,
                          icon: const Icon(Icons.key_outlined),
                          label: const Text('ورود به تیم با کد دعوت'),
                        ),
                      ],
                      if (busy)
                        const Padding(
                          padding: EdgeInsets.all(16),
                          child: Center(child: CircularProgressIndicator()),
                        ),
                    ],
                  ),
                );
              },
            ),
          ),
        ),
        bottomNavigationBar: const NexoBottomNavigation(selectedIndex: 2),
      );
}

class _TeamEmptyState extends StatelessWidget {
  const _TeamEmptyState();

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
          child: Column(
            children: [
              Container(
                width: 76,
                height: 76,
                decoration: BoxDecoration(
                  color: NexoTheme.amber.withValues(alpha: .12),
                  shape: BoxShape.circle,
                  border:
                      Border.all(color: NexoTheme.amber.withValues(alpha: .5)),
                ),
                child: const Icon(Icons.groups_2_outlined,
                    color: NexoTheme.amber, size: 38),
              ),
              const SizedBox(height: 16),
              Text('هنوز عضو تیمی نیستید',
                  style: Theme.of(context).textTheme.titleLarge,
                  textAlign: TextAlign.center),
              const SizedBox(height: 8),
              const Text(
                'یک تیم بسازید یا با کد دعوت به تیم دوستانتان بپیوندید.',
                textAlign: TextAlign.center,
                style: TextStyle(color: NexoTheme.muted),
              ),
            ],
          ),
        ),
      );
}

class _TeamIdentityCard extends StatelessWidget {
  const _TeamIdentityCard({required this.team});

  final NexoTeam team;

  @override
  Widget build(BuildContext context) {
    final count = team.members.length;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: AlignmentDirectional.topStart,
            end: AlignmentDirectional.bottomEnd,
            colors: [NexoTheme.surfaceRaised, NexoTheme.surface],
          ),
        ),
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Container(
                  width: 60,
                  height: 60,
                  decoration: BoxDecoration(
                    color: NexoTheme.amber,
                    borderRadius: BorderRadius.circular(18),
                  ),
                  child: const Icon(Icons.shield_outlined,
                      color: NexoTheme.background, size: 34),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(team.name,
                          style: Theme.of(context).textTheme.titleLarge),
                      const SizedBox(height: 4),
                      Text('کاپیتان: ${team.captainName}',
                          style: const TextStyle(color: NexoTheme.muted)),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                const Icon(Icons.people_alt_outlined,
                    color: NexoTheme.amberSoft, size: 20),
                Text('${_faNumber(count)} از ۸ عضو',
                    style: const TextStyle(fontWeight: FontWeight.w700)),
                _TeamStatusChip(label: _teamStatus(team.status)),
              ],
            ),
            const SizedBox(height: 10),
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: LinearProgressIndicator(
                value: (count / 8).clamp(0, 1),
                minHeight: 8,
                backgroundColor: NexoTheme.background,
                valueColor:
                    const AlwaysStoppedAnimation<Color>(NexoTheme.amber),
                semanticsLabel: 'ظرفیت اعضای تیم',
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _TeamReadiness extends StatelessWidget {
  const _TeamReadiness({required this.team});

  final NexoTeam team;

  @override
  Widget build(BuildContext context) {
    final ready = team.eligibleForGame;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: (ready ? NexoTheme.success : NexoTheme.information)
            .withValues(alpha: .1),
        borderRadius: BorderRadius.circular(NexoTheme.radius),
        border: Border.all(
            color: (ready ? NexoTheme.success : NexoTheme.information)
                .withValues(alpha: .55)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(ready ? Icons.check_circle_outline : Icons.info_outline,
              color: ready ? NexoTheme.success : NexoTheme.information),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  ready ? 'آماده ثبت‌نام در بازی' : 'هنوز آماده ثبت‌نام نیست',
                  style: TextStyle(
                    color: ready ? NexoTheme.success : NexoTheme.information,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  ready
                      ? 'تعداد اعضای تیم برای بازی مناسب است.'
                      : 'برای ثبت‌نام، تیم باید ۴ تا ۸ عضو فعال داشته باشد.',
                  style: const TextStyle(color: NexoTheme.muted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _TeamStatusChip extends StatelessWidget {
  const _TeamStatusChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: NexoTheme.surface,
          border: Border.all(color: NexoTheme.border),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(label, style: const TextStyle(color: NexoTheme.muted)),
      );
}

class _MemberTile extends StatelessWidget {
  const _MemberTile({
    required this.member,
    required this.isCaptain,
    required this.canRemove,
    required this.busy,
    required this.onRemove,
  });

  final TeamMember member;
  final bool isCaptain;
  final bool canRemove;
  final bool busy;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: 8),
        child: ListTile(
          minVerticalPadding: 10,
          leading: CircleAvatar(
            backgroundColor: isCaptain
                ? NexoTheme.amber.withValues(alpha: .18)
                : NexoTheme.surfaceRaised,
            foregroundColor: isCaptain ? NexoTheme.amber : NexoTheme.foreground,
            child: Text(_initial(member.name),
                style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
          title: Text(member.name),
          subtitle: Text(isCaptain ? 'کاپیتان تیم' : 'عضو تیم'),
          trailing: canRemove
              ? IconButton.filledTonal(
                  tooltip: 'حذف ${member.name} از تیم',
                  onPressed: busy ? null : onRemove,
                  icon: const Icon(Icons.person_remove_outlined),
                )
              : null,
        ),
      );
}

class _CaptainActions extends StatelessWidget {
  const _CaptainActions({
    required this.busy,
    required this.onInvite,
    required this.onTransfer,
  });

  final bool busy;
  final VoidCallback onInvite;
  final VoidCallback onTransfer;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          FilledButton.icon(
            onPressed: busy ? null : onInvite,
            icon: const Icon(Icons.vpn_key_outlined),
            label: const Text('دریافت کد دعوت'),
          ),
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: busy ? null : onTransfer,
            icon: const Icon(Icons.swap_horiz),
            label: const Text('انتقال کاپیتانی'),
          ),
        ],
      );
}

class _TeamNameDialog extends StatefulWidget {
  const _TeamNameDialog();

  @override
  State<_TeamNameDialog> createState() => _TeamNameDialogState();
}

class _TeamNameDialogState extends State<_TeamNameDialog> {
  final controller = TextEditingController();

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('ساخت تیم'),
        content: TextField(
          controller: controller,
          autofocus: true,
          textDirection: TextDirection.rtl,
          textInputAction: TextInputAction.done,
          onSubmitted: (_) => _submit(context),
          decoration: const InputDecoration(labelText: 'نام تیم'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => _submit(context),
            child: const Text('ادامه'),
          ),
        ],
      );

  void _submit(BuildContext context) {
    final value = controller.text.trim();
    if (value.isNotEmpty) Navigator.pop(context, value);
  }
}

String _teamStatus(String status) => switch (status) {
      'ACTIVE' => 'فعال',
      'ARCHIVED' => 'بایگانی‌شده',
      _ => 'وضعیت نامشخص',
    };

String _initial(String name) {
  final value = name.trim();
  return value.isEmpty ? '؟' : String.fromCharCode(value.runes.first);
}

String _faNumber(int value) => value.toString().replaceAllMapped(
      RegExp(r'[0-9]'),
      (match) => '۰۱۲۳۴۵۶۷۸۹'[int.parse(match[0]!)],
    );

String teamError(Object error) {
  if (ApiClient.isNetworkFailure(error)) {
    return 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.';
  }
  final code = error is ApiError ? error.code : '';
  return switch (code) {
    'ALREADY_TEAM_MEMBER' => 'شما هم‌اکنون عضو این تیم هستید.',
    'TEAM_FULL' => 'ظرفیت تیم تکمیل است؛ حداکثر ۸ عضو می‌توانند عضو باشند.',
    'INVITE_EXPIRED' => 'کد دعوت منقضی شده است؛ کد تازه‌ای بگیرید.',
    'INVITE_REVOKED' => 'این کد دعوت لغو شده است.',
    'AUTHORIZATION_DENIED' ||
    'AUTHORIZATION_REQUIRED' =>
      'اجازهٔ انجام این عملیات را ندارید.',
    'ACTION_NOT_ALLOWED' => 'این عملیات در وضعیت فعلی مجاز نیست.',
    'CONFLICT' => 'وضعیت عضویت تغییر کرده است؛ فهرست را تازه کنید.',
    'VALIDATION_FAILED' => 'نام تیم یا کد دعوت را بررسی کنید.',
    'RATE_LIMITED' =>
      'درخواست‌های زیادی فرستاده‌اید؛ کمی بعد دوباره تلاش کنید.',
    'RESOURCE_NOT_FOUND' => 'تیم یا کد دعوت پیدا نشد.',
    _ => 'عملیات تیم انجام نشد. دوباره تلاش کنید.',
  };
}
