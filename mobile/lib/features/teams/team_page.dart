import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_routes.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import 'join_team_page.dart';
import 'team_invitations_page.dart';
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
  late Future<List<TeamInvitation>> receivedInvitations;
  String? selectedTeamId;
  bool busy = false;

  @override
  void initState() {
    super.initState();
    repository = widget.repository ?? TeamRepository(widget.auth.api);
    teams = repository.list();
    receivedInvitations = repository.incomingInvitations();
  }

  Future<void> refresh() async {
    setState(() {
      teams = repository.list();
      receivedInvitations = repository.incomingInvitations();
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

  Future<void> joinTeam({bool scan = false}) async {
    final joined = await Navigator.of(context).push<bool>(
      MaterialPageRoute<bool>(builder: (_) => JoinTeamPage(auth: widget.auth, autoScan: scan)),
    );
    if (joined == true && mounted) {
      setState(() {
        teams = repository.list();
      });
    }
  }

  Future<void> inspectMember(NexoTeam team, TeamMember member, {required bool isCaptain, required bool canRemove}) async {
    final remove = await showModalBottomSheet<bool>(
      context: context,
      useSafeArea: true,
      builder: (sheetContext) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 18, 20, 28),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          CircleAvatar(radius: 30, child: Text(_initial(member.name))),
          const SizedBox(height: 12),
          Text(member.name, style: Theme.of(sheetContext).textTheme.titleLarge),
          const SizedBox(height: 4),
          Text(isCaptain ? 'کاپیتان تیم' : 'عضو تیم', style: const TextStyle(color: NexoTheme.muted)),
          if (canRemove) ...[
            const SizedBox(height: 18),
            OutlinedButton.icon(onPressed: () => Navigator.pop(sheetContext, true), icon: const Icon(Icons.person_remove_outlined), label: const Text('حذف از ترکیب تیم')),
          ],
        ]),
      ),
    );
    if (remove != true || !mounted) return;
    final confirmed = await confirm('عضویت ${member.name} از این تیم حذف شود؟', action: 'حذف عضو');
    if (confirmed) await run(() => repository.remove(team.id, member.userId));
  }

  Future<void> invitePlayer(NexoTeam team) async {
    final sent = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) =>
          InvitePlayerDialog(repository: repository, teamId: team.id),
    );
    if (sent == true && mounted) {
      await refresh();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('دعوت برای بازیکن ارسال شد.')),
      );
    }
  }

  Future<void> openInvitations() async {
    await Navigator.of(context).push<void>(MaterialPageRoute<void>(
      builder: (_) => TeamInvitationsPage(auth: widget.auth),
    ));
    if (mounted) await refresh();
  }

  Future<void> openSentInvitations(NexoTeam team) async {
    await Navigator.of(context).push<void>(MaterialPageRoute<void>(
      builder: (_) => SentTeamInvitationsPage(
        auth: widget.auth,
        teamId: team.id,
        teamName: team.name,
      ),
    ));
    if (mounted) await refresh();
  }

  void _handleBack() {
    final navigator = Navigator.of(context);
    if (navigator.canPop()) {
      navigator.pop();
      return;
    }
    navigator.pushNamedAndRemoveUntil(NexoRoutes.home, (_) => false);
  }

  Future<void> makeInvite(NexoTeam team) async {
    if (busy) return;
    setState(() => busy = true);
    try {
      final result = await repository.createInvite(team.id);
      final code = result['invite_code'] as String?;
      if (code == null || !mounted) return;
      final link = 'nexo://invite/$code';
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        builder: (sheetContext) => Padding(
          padding: EdgeInsets.fromLTRB(20, 12, 20, MediaQuery.viewInsetsOf(sheetContext).bottom + 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(child: Container(width: 42, height: 4, decoration: BoxDecoration(color: NexoTheme.border, borderRadius: BorderRadius.circular(8)))),
              const SizedBox(height: 18),
              Text('دعوت به ${team.name}', style: Theme.of(sheetContext).textTheme.titleLarge, textAlign: TextAlign.center),
              const SizedBox(height: 14),
              Center(child: Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18)), child: QrImageView(data: link, size: 192, backgroundColor: Colors.white, errorCorrectionLevel: QrErrorCorrectLevel.M))),
              const SizedBox(height: 12),
              const Text('اعتبار دعوت: ۷ روز؛ با لغو دعوت، لینک و QR از کار می‌افتند.', textAlign: TextAlign.center, style: TextStyle(color: NexoTheme.muted)),
              const SizedBox(height: 12),
              SelectableText(code, textDirection: TextDirection.ltr, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700, letterSpacing: 1.2)),
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: () async {
                  try {
                    await const MethodChannel('nexo/share').invokeMethod<void>('shareText', {'text': 'دعوت به تیم ${team.name}\n$link'});
                  } on PlatformException {
                    await Clipboard.setData(ClipboardData(text: link));
                    if (sheetContext.mounted) ScaffoldMessenger.of(sheetContext).showSnackBar(const SnackBar(content: Text('لینک دعوت کپی شد.')));
                  }
                },
                icon: const Icon(Icons.share_outlined),
                label: const Text('اشتراک‌گذاری لینک'),
              ),
              OutlinedButton.icon(
                onPressed: () async {
                  await Clipboard.setData(ClipboardData(text: code));
                  if (sheetContext.mounted) ScaffoldMessenger.of(sheetContext).showSnackBar(const SnackBar(content: Text('کد دعوت کپی شد.')));
                },
                icon: const Icon(Icons.copy_outlined),
                label: const Text('کپی کد دعوت'),
              ),
            ],
          ),
        ),
      );
    } catch (error) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(teamError(error))));
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
          title: const Text('لابی تیم'),
          leading: IconButton(
            tooltip: 'بازگشت',
            icon: const Icon(Icons.arrow_back),
            onPressed: _handleBack,
          ),
          actions: [
            FutureBuilder<List<TeamInvitation>>(
              future: receivedInvitations,
              builder: (context, snapshot) => IconButton(
                tooltip: snapshot.hasData && snapshot.data!.isNotEmpty
                    ? 'دعوت‌های دریافتی (${snapshot.data!.where((item) => item.isPending).length})'
                    : 'دعوت‌های دریافتی',
                onPressed: openInvitations,
                icon: Badge(
                  isLabelVisible: snapshot.hasData &&
                      snapshot.data!.any((item) => item.isPending),
                  child: const Icon(Icons.mark_email_unread_outlined),
                ),
              ),
            ),
          ],
        ),
        body: Directionality(
          textDirection: TextDirection.rtl,
          child: SafeArea(
            child: FutureBuilder<List<NexoTeam>>(
              future: teams,
              builder: (context, snapshot) {
                if (!snapshot.hasData &&
                    snapshot.connectionState != ConnectionState.done) {
                  return const _TeamLobbySkeleton();
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
                if (items.isEmpty) {
                  return RefreshIndicator(
                    onRefresh: refresh,
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.fromLTRB(20, 28, 20, 32),
                      children: [
                        const _TeamEmptyState(),
                        const SizedBox(height: 18),
                        FilledButton.icon(
                          onPressed: busy ? null : createTeam,
                          icon: const Icon(Icons.add),
                          label: const Text('ساخت تیم'),
                        ),
                        const SizedBox(height: 8),
                        _TeamJoinActions(onScan: () => joinTeam(scan: true), onCode: joinTeam),
                      ],
                    ),
                  );
                }
                final team = items
                        .where((item) => item.id == selectedTeamId)
                        .firstOrNull ??
                    items.first;
                if (selectedTeamId != team.id) {
                  WidgetsBinding.instance.addPostFrameCallback((_) {
                    if (mounted && selectedTeamId != team.id) {
                      setState(() => selectedTeamId = team.id);
                    }
                  });
                }
                final userId = widget.auth.user?['id'] as String? ?? '';
                final isCaptain =
                    team.status == 'ACTIVE' && team.captainUserId == userId;
                return RefreshIndicator(
                  onRefresh: refresh,
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
                    children: [
                      _TeamPicker(
                        teams: items,
                        selected: team,
                        onChanged: (value) => setState(() => selectedTeamId = value?.id),
                        onCreateTeam: createTeam,
                      ),
                      _TeamJoinActions(onScan: () => joinTeam(scan: true), onCode: joinTeam),
                      const SizedBox(height: 12),
                      AnimatedSwitcher(
                        duration: const Duration(milliseconds: 220),
                        child: _TeamIdentityCard(
                          key: ValueKey(team.id),
                          team: team,
                        ),
                      ),
                      const SizedBox(height: 14),
                      _TeamReadiness(team: team),
                      const SizedBox(height: 12),
                      if (isCaptain)
                        FilledButton.icon(
                          onPressed: busy ? null : () => makeInvite(team),
                          icon: const Icon(Icons.person_add_alt_1),
                          label: const Text('دعوت به تیم'),
                        ),
                      if (isCaptain) const SizedBox(height: 16),
                      _TeamActionsMenu(
                        team: team,
                        isCaptain: isCaptain,
                        busy: busy,
                        onCreateCode: () => invitePlayer(team),
                        onSentInvitations: () => openSentInvitations(team),
                        onTransfer: () => transferCaptaincy(team),
                        onLeave: () async {
                          final confirmed = await confirm(
                            'از تیم «${team.name}» خارج شوید؟',
                            action: 'خروج از تیم',
                          );
                          if (confirmed) {
                            await run(() => repository.leave(team.id));
                          }
                        },
                      ),
                      AnimatedSwitcher(
                        duration: const Duration(milliseconds: 220),
                        child: _TeamSlots(
                          key: ValueKey('slots-${team.id}'),
                          team: team,
                          onMemberTap: (member, isCaptain) => inspectMember(
                            team,
                            member,
                            isCaptain: isCaptain,
                            canRemove: isCaptain && member.userId != userId,
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                      if (isCaptain)
                        _PendingJoinRequests(
                          teamId: team.id,
                          repository: repository,
                          onChanged: refresh,
                        ),
                      if (isCaptain) ...[
                        _PendingInvitationsSummary(
                          future: repository.outgoingInvitations(team.id),
                          onOpen: () => openSentInvitations(team),
                          onRetry: refresh,
                        ),
                        const SizedBox(height: 12),
                      ],
                      if (busy)
                        const Padding(
                          padding: EdgeInsets.all(12),
                          child: LinearProgressIndicator(),
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

class _TeamLobbySkeleton extends StatelessWidget {
  const _TeamLobbySkeleton();

  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.all(16),
        children: [
          const NexoSkeleton(height: 148, radius: NexoTheme.radiusLarge),
          const SizedBox(height: 16),
          const NexoSkeleton(height: 48),
          const SizedBox(height: 24),
          for (var index = 0; index < 4; index++) ...[
            const NexoSkeleton(height: 68),
            const SizedBox(height: 8),
          ],
        ],
      );
}

class _TeamPicker extends StatelessWidget {
  const _TeamPicker({
    required this.teams,
    required this.selected,
    required this.onChanged,
    required this.onCreateTeam,
  });

  final List<NexoTeam> teams;
  final NexoTeam selected;
  final ValueChanged<NexoTeam?> onChanged;
  final VoidCallback onCreateTeam;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 12),
      child: Row(children: [
        Expanded(child: DropdownButtonFormField<NexoTeam>(
          initialValue: selected,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'تیم فعال', prefixIcon: Icon(Icons.groups_2_outlined)),
          items: [for (final team in teams) DropdownMenuItem(value: team, child: Text(team.name))],
          selectedItemBuilder: (context) => [for (final team in teams) Align(alignment: AlignmentDirectional.centerStart, child: Text(team.name, maxLines: 1, overflow: TextOverflow.ellipsis))],
          onChanged: onChanged,
        )),
        const SizedBox(width: 8),
        IconButton.filledTonal(tooltip: 'ساخت تیم جدید', onPressed: onCreateTeam, icon: const Icon(Icons.add)),
      ]),
      );
}

class _TeamJoinActions extends StatelessWidget {
  const _TeamJoinActions({required this.onScan, required this.onCode});
  final VoidCallback onScan;
  final VoidCallback onCode;

  @override
  Widget build(BuildContext context) => LayoutBuilder(builder: (context, constraints) {
        final scan = OutlinedButton.icon(onPressed: onScan, icon: const Icon(Icons.qr_code_scanner), label: const Text('اسکن QR'));
        final code = OutlinedButton.icon(onPressed: onCode, icon: const Icon(Icons.keyboard_alt_outlined), label: const Text('ورود کد دعوت'));
        if (constraints.maxWidth < 370) return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [scan, const SizedBox(height: 8), code]);
        return Row(children: [Expanded(child: scan), const SizedBox(width: 10), Expanded(child: code)]);
      });
}

class _TeamSectionTitle extends StatelessWidget {
  const _TeamSectionTitle({required this.title, this.trailing});

  final String title;
  final String? trailing;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Expanded(
            child: Text(title, style: Theme.of(context).textTheme.titleMedium),
          ),
          if (trailing != null)
            Text(trailing!, style: const TextStyle(color: NexoTheme.muted)),
        ],
      );
}

class _TeamSlots extends StatelessWidget {
  const _TeamSlots({required this.team, required this.onMemberTap, super.key});

  final NexoTeam team;
  final void Function(TeamMember member, bool isCaptain) onMemberTap;

  @override
  Widget build(BuildContext context) {
    final members = team.members.take(8).toList(growable: false);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _TeamSectionTitle(
              title: 'ترکیب تیم',
              trailing: '${_faNumber(members.length)} / ۸',
            ),
            const SizedBox(height: 14),
            Wrap(
              spacing: 10,
              runSpacing: 12,
              children: [
                for (var index = 0; index < 8; index++)
                  _PlayerSlot(
                    member: index < members.length ? members[index] : null,
                    isCaptain: index < members.length &&
                        members[index].userId == team.captainUserId,
                    index: index,
                    onTap: index < members.length ? () => onMemberTap(members[index], members[index].userId == team.captainUserId) : null,
                  ),
              ],
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
    final color = ready ? NexoTheme.success : NexoTheme.information;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .1),
        borderRadius: BorderRadius.circular(NexoTheme.radiusSmall),
        border: Border.all(color: color.withValues(alpha: .45)),
      ),
      child: Row(children: [
        Icon(ready ? Icons.check_circle_outline : Icons.info_outline,
            color: color, size: 20),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            ready
                ? 'آماده ثبت‌نام در بازی'
                : 'برای بازی به ۴ تا ۸ عضو فعال نیاز دارید.',
            style: TextStyle(color: color, fontWeight: FontWeight.w700),
          ),
        ),
      ]),
    );
  }
}

class _PlayerSlot extends StatelessWidget {
  const _PlayerSlot({
    required this.member,
    required this.isCaptain,
    required this.index,
    this.onTap,
  });

  final TeamMember? member;
  final bool isCaptain;
  final int index;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final empty = member == null;
    return SizedBox(
      width: 72,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Column(
        children: [
          Stack(
            clipBehavior: Clip.none,
            children: [
              CircleAvatar(
                radius: 25,
                backgroundColor: empty
                    ? NexoTheme.background
                    : isCaptain
                        ? NexoTheme.amber.withValues(alpha: .18)
                        : NexoTheme.surfaceRaised,
                foregroundColor: isCaptain ? NexoTheme.amber : NexoTheme.muted,
                child: empty
                    ? const Icon(Icons.add, size: 20)
                    : Text(_initial(member!.name)),
              ),
              if (isCaptain)
                const PositionedDirectional(
                  bottom: -3,
                  end: -3,
                  child: Icon(Icons.military_tech,
                      size: 17, color: NexoTheme.amber),
                ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            empty ? 'جای خالی' : member!.name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 11,
              color: empty ? NexoTheme.muted : NexoTheme.foreground,
              fontWeight: isCaptain ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
          if (empty)
            Text('${index + 1}',
                style: const TextStyle(fontSize: 10, color: NexoTheme.muted)),
        ],
          ),
        ),
      ),
    );
  }
}

class _PendingInvitationsSummary extends StatelessWidget {
  const _PendingInvitationsSummary({
    required this.future,
    required this.onOpen,
    required this.onRetry,
  });

  final Future<List<SentTeamInvitation>> future;
  final VoidCallback onOpen;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => FutureBuilder<List<SentTeamInvitation>>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.hasError) {
            return Card(
              child: ListTile(
                title: const Text('وضعیت دعوت‌ها دریافت نشد'),
                trailing: IconButton(
                  tooltip: 'تلاش دوباره',
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh),
                ),
              ),
            );
          }
          if (!snapshot.hasData) {
            return const Card(
              child: ListTile(
                leading: SizedBox.square(
                  dimension: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
                title: Text('در حال بررسی دعوت‌ها'),
              ),
            );
          }
          final pending = snapshot.data!.where((item) => item.isPending).length;
          return Card(
            child: ListTile(
              leading: Icon(
                pending > 0 ? Icons.mark_email_unread_outlined : Icons.outbox,
                color: pending > 0 ? NexoTheme.amber : NexoTheme.muted,
              ),
              title: Text(pending > 0
                  ? '${_faNumber(pending)} دعوت در انتظار پاسخ'
                  : 'دعوت در انتظار پاسخ ندارید'),
              trailing: TextButton(
                onPressed: onOpen,
                child: const Text('مشاهده'),
              ),
            ),
          );
        },
      );
}

class _PendingJoinRequests extends StatefulWidget {
  const _PendingJoinRequests({required this.teamId, required this.repository, required this.onChanged});
  final String teamId;
  final TeamRepository repository;
  final Future<void> Function() onChanged;

  @override
  State<_PendingJoinRequests> createState() => _PendingJoinRequestsState();
}

class _PendingJoinRequestsState extends State<_PendingJoinRequests> {
  late Future<List<JoinRequest>> requests;
  bool busy = false;

  @override
  void initState() {
    super.initState();
    requests = widget.repository.joinRequests(widget.teamId);
  }

  Future<void> decide(JoinRequest request, bool accept) async {
    if (busy) return;
    setState(() => busy = true);
    try {
      await widget.repository.respondToJoinRequest(widget.teamId, request.id, accept: accept);
      requests = widget.repository.joinRequests(widget.teamId);
      await widget.onChanged();
    } catch (error) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(teamError(error))));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<List<JoinRequest>>(
        future: requests,
        builder: (context, snapshot) {
          if (snapshot.hasError) {
            return Card(child: ListTile(title: const Text('درخواست‌های پیوستن دریافت نشد'), trailing: IconButton(onPressed: () => setState(() => requests = widget.repository.joinRequests(widget.teamId)), icon: const Icon(Icons.refresh))));
          }
          if (!snapshot.hasData) return const Card(child: ListTile(leading: SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2)), title: Text('در حال بررسی درخواست‌های پیوستن')));
          final pending = snapshot.data!.where((request) => request.status == 'PENDING').toList(growable: false);
          if (pending.isEmpty) return const SizedBox.shrink();
          return Card(child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              _TeamSectionTitle(title: 'درخواست‌های پیوستن', trailing: _faNumber(pending.length)),
              for (final request in pending) ListTile(
                contentPadding: EdgeInsets.zero,
                leading: const CircleAvatar(child: Icon(Icons.person_outline)),
                title: Text(request.playerName),
                subtitle: const Text('در انتظار تصمیم کاپیتان'),
                trailing: Wrap(spacing: 2, children: [
                  IconButton(tooltip: 'پذیرفتن', onPressed: busy ? null : () => decide(request, true), icon: const Icon(Icons.check_circle_outline, color: NexoTheme.success)),
                  IconButton(tooltip: 'رد کردن', onPressed: busy ? null : () => decide(request, false), icon: const Icon(Icons.cancel_outlined, color: NexoTheme.danger)),
                ]),
              ),
            ]),
          ));
        },
      );
}

class _TeamActionsMenu extends StatelessWidget {
  const _TeamActionsMenu({
    required this.team,
    required this.isCaptain,
    required this.busy,
    required this.onCreateCode,
    required this.onSentInvitations,
    required this.onTransfer,
    required this.onLeave,
  });

  final NexoTeam team;
  final bool isCaptain;
  final bool busy;
  final VoidCallback onCreateCode;
  final VoidCallback onSentInvitations;
  final VoidCallback onTransfer;
  final VoidCallback onLeave;

  @override
  Widget build(BuildContext context) => Align(
        alignment: AlignmentDirectional.centerEnd,
        child: PopupMenuButton<String>(
          enabled: !busy,
          tooltip: 'عملیات تیم',
          icon: const Icon(Icons.more_horiz),
          onSelected: (value) {
            switch (value) {
              case 'code':
                onCreateCode();
                return;
              case 'sent':
                onSentInvitations();
                return;
              case 'transfer':
                onTransfer();
                return;
              case 'leave':
                onLeave();
                return;
            }
          },
          itemBuilder: (context) => [
            if (isCaptain && team.status == 'ACTIVE') ...[
              const PopupMenuItem(value: 'code', child: Text('دعوت با شناسه بازیکن')),
              const PopupMenuItem(
                  value: 'sent', child: Text('دعوت‌های ارسالی')),
              const PopupMenuItem(
                  value: 'transfer', child: Text('انتقال کاپیتانی')),
            ] else if (team.status == 'ACTIVE')
              const PopupMenuItem(value: 'leave', child: Text('خروج از تیم')),
          ],
        ),
      );
}

class _TeamIdentityCard extends StatelessWidget {
  const _TeamIdentityCard({required this.team, super.key});

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
