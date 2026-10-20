import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import 'team_repository.dart';

class TeamInvitationsPage extends StatefulWidget {
  const TeamInvitationsPage({required this.auth, super.key});

  final AuthController auth;

  @override
  State<TeamInvitationsPage> createState() => _TeamInvitationsPageState();
}

class _TeamInvitationsPageState extends State<TeamInvitationsPage> {
  late final TeamRepository repository = TeamRepository(widget.auth.api);
  late Future<List<TeamInvitation>> invitations =
      repository.incomingInvitations();
  String? busyInvitation;

  Future<void> refresh() async {
    setState(() => invitations = repository.incomingInvitations());
    try {
      await invitations;
    } catch (_) {
      // Keep the error state visible for retry.
    }
  }

  Future<void> respond(TeamInvitation invitation, bool accept) async {
    if (busyInvitation != null) return;
    setState(() => busyInvitation = invitation.id);
    try {
      await repository.respondToInvitation(invitation.id, accept: accept);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(accept ? 'درخواست عضویت پذیرفته شد.' : 'دعوت رد شد.'),
        ));
        setState(() => invitations = repository.incomingInvitations());
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(_invitationError(error))),
        );
      }
    } finally {
      if (mounted) setState(() => busyInvitation = null);
    }
  }

  @override
  Widget build(BuildContext context) => DefaultTabController(
        length: 2,
        child: Scaffold(
          appBar: AppBar(
            title: const Text('دعوت‌های تیم'),
            bottom: const TabBar(
              tabs: [
                Tab(text: 'دریافتی', icon: Icon(Icons.inbox_outlined)),
                Tab(text: 'ارسالی', icon: Icon(Icons.outbox_outlined)),
              ],
            ),
          ),
          body: SafeArea(
            child: TabBarView(
              children: [
                _incomingInvitations(),
                _OutgoingInvitationsTab(auth: widget.auth),
              ],
            ),
          ),
        ),
      );

  Widget _incomingInvitations() => FutureBuilder<List<TeamInvitation>>(
        future: invitations,
        builder: (context, snapshot) {
          if (!snapshot.hasData &&
              snapshot.connectionState != ConnectionState.done) {
            return const _InvitationListSkeleton();
          }
          if (snapshot.hasError || !snapshot.hasData) {
            final error =
                snapshot.error ?? StateError('Invitations unavailable');
            return NexoErrorView(
              message: _invitationError(error),
              offline: ApiClient.isNetworkFailure(error),
              onRetry: refresh,
            );
          }
          final values = snapshot.data!;
          if (values.isEmpty) {
            return const NexoEmptyView(
              title: 'دعوت تازه‌ای نداری',
              message:
                  'دعوت‌های تیمی که برای شناسه بازیکن شما ارسال شوند اینجا نمایش داده می‌شوند.',
              icon: Icons.mark_email_unread_outlined,
            );
          }
          return RefreshIndicator(
            onRefresh: refresh,
            child: ListView.separated(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              itemCount: values.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (context, index) {
                final invitation = values[index];
                final pending = invitation.isPending;
                final isBusy = busyInvitation == invitation.id;
                return Card(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(children: [
                          const Icon(Icons.groups_2_outlined),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(invitation.teamName,
                                style: Theme.of(context).textTheme.titleMedium),
                          ),
                          _InvitationStatusChip(
                              label: _status(invitation.effectiveStatus)),
                        ]),
                        const SizedBox(height: 6),
                        Text('دعوت از طرف ${invitation.captainName}',
                            style: const TextStyle(color: NexoTheme.muted)),
                        if (pending) ...[
                          const SizedBox(height: 12),
                          Row(children: [
                            Expanded(
                              child: OutlinedButton(
                                onPressed: isBusy
                                    ? null
                                    : () => respond(invitation, false),
                                child: const Text('رد دعوت'),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: FilledButton(
                                onPressed: isBusy
                                    ? null
                                    : () => respond(invitation, true),
                                child: isBusy
                                    ? const SizedBox.square(
                                        dimension: 18,
                                        child: CircularProgressIndicator(
                                            strokeWidth: 2),
                                      )
                                    : const Text('پذیرش عضویت'),
                              ),
                            ),
                          ]),
                        ],
                      ],
                    ),
                  ),
                );
              },
            ),
          );
        },
      );

  String _status(String status) => switch (status) {
        'PENDING' => 'در انتظار پاسخ شما',
        'ACCEPTED' => 'پذیرفته‌شده',
        'DECLINED' || 'REJECTED' => 'ردشده',
        'EXPIRED' => 'منقضی‌شده',
        'REVOKED' => 'لغوشده',
        _ => 'وضعیت نامشخص',
      };
}

class _InvitationListSkeleton extends StatelessWidget {
  const _InvitationListSkeleton();

  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.all(16),
        children: [
          for (var index = 0; index < 4; index++) ...[
            const NexoSkeleton(height: 104),
            const SizedBox(height: 10),
          ],
        ],
      );
}

class _InvitationStatusChip extends StatelessWidget {
  const _InvitationStatusChip({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: NexoTheme.surfaceRaised,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: NexoTheme.border),
        ),
        child: Text(label, style: const TextStyle(fontSize: 12)),
      );
}

class _OutgoingInvitationItem {
  const _OutgoingInvitationItem({required this.team, required this.invitation});

  final NexoTeam team;
  final SentTeamInvitation invitation;
}

class _OutgoingInvitationsTab extends StatefulWidget {
  const _OutgoingInvitationsTab({required this.auth});

  final AuthController auth;

  @override
  State<_OutgoingInvitationsTab> createState() =>
      _OutgoingInvitationsTabState();
}

class _OutgoingInvitationsTabState extends State<_OutgoingInvitationsTab> {
  late final TeamRepository repository = TeamRepository(widget.auth.api);
  late Future<List<_OutgoingInvitationItem>> invitations = _load();
  String? busyId;

  Future<List<_OutgoingInvitationItem>> _load() async {
    final userId = widget.auth.user?['id'] as String? ?? '';
    final teams = (await repository.list())
        .where(
            (team) => team.status == 'ACTIVE' && team.captainUserId == userId)
        .toList(growable: false);
    final lists = await Future.wait([
      for (final team in teams) repository.outgoingInvitations(team.id),
    ]);
    return [
      for (var index = 0; index < teams.length; index++)
        for (final invitation in lists[index])
          _OutgoingInvitationItem(team: teams[index], invitation: invitation),
    ];
  }

  Future<void> refresh() async {
    setState(() => invitations = _load());
    try {
      await invitations;
    } catch (_) {
      // The error state provides a retry action.
    }
  }

  Future<void> revoke(_OutgoingInvitationItem item) async {
    if (busyId != null) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('لغو دعوت'),
        content: Text('دعوت برای ${item.invitation.recipientName} لغو شود؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('لغو دعوت'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() => busyId = item.invitation.id);
    try {
      await repository.revokeInvitation(item.team.id, item.invitation.id);
      if (mounted) setState(() => invitations = _load());
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(_invitationError(error))),
        );
      }
    } finally {
      if (mounted) setState(() => busyId = null);
    }
  }

  @override
  Widget build(BuildContext context) =>
      FutureBuilder<List<_OutgoingInvitationItem>>(
        future: invitations,
        builder: (context, snapshot) {
          if (!snapshot.hasData &&
              snapshot.connectionState != ConnectionState.done) {
            return const _InvitationListSkeleton();
          }
          if (snapshot.hasError || !snapshot.hasData) {
            final error =
                snapshot.error ?? StateError('Invitations unavailable');
            return NexoErrorView(
              message: _invitationError(error),
              offline: ApiClient.isNetworkFailure(error),
              onRetry: refresh,
            );
          }
          final values = snapshot.data!;
          if (values.isEmpty) {
            return const NexoEmptyView(
              title: 'دعوت ارسالی ندارید',
              message:
                  'دعوت‌هایی که از تیم‌های تحت کاپیتانی شما ارسال شده‌اند اینجا هستند.',
              icon: Icons.outbox_outlined,
            );
          }
          return RefreshIndicator(
            onRefresh: refresh,
            child: ListView.separated(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              itemCount: values.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (context, index) {
                final item = values[index];
                final pending =
                    item.invitation.isPending && !_isExpired(item.invitation);
                final busy = busyId == item.invitation.id;
                return Card(
                  child: ListTile(
                    leading: const CircleAvatar(
                      backgroundColor: NexoTheme.surfaceRaised,
                      child: Icon(Icons.person_outline),
                    ),
                    title: Text(item.invitation.recipientName),
                    subtitle: Text(
                        '${item.team.name} · ${_sentStatus(item.invitation)}'),
                    trailing: pending
                        ? IconButton(
                            tooltip: 'لغو دعوت',
                            onPressed: busy ? null : () => revoke(item),
                            icon: busy
                                ? const SizedBox.square(
                                    dimension: 20,
                                    child: CircularProgressIndicator(
                                        strokeWidth: 2),
                                  )
                                : const Icon(Icons.cancel_outlined),
                          )
                        : null,
                  ),
                );
              },
            ),
          );
        },
      );

  bool _isExpired(SentTeamInvitation invitation) {
    final expiry = invitation.expiresAt;
    return expiry != null &&
        DateTime.tryParse(expiry)?.isBefore(DateTime.now()) == true;
  }

  String _sentStatus(SentTeamInvitation invitation) {
    if (invitation.revokedAt != null) return 'لغوشده';
    if (_isExpired(invitation)) return 'منقضی‌شده';
    if (invitation.decision == 'CODE') {
      return 'کد دعوت · ${invitation.usesCount} استفاده';
    }
    return switch (invitation.decision) {
      'PENDING' => 'در انتظار پاسخ',
      'ACCEPTED' => 'پذیرفته‌شده',
      'DECLINED' || 'REJECTED' => 'ردشده',
      'EXPIRED' => 'منقضی‌شده',
      _ => 'وضعیت نامشخص',
    };
  }
}

class SentTeamInvitationsPage extends StatefulWidget {
  const SentTeamInvitationsPage({
    required this.auth,
    required this.teamId,
    required this.teamName,
    super.key,
  });

  final AuthController auth;
  final String teamId;
  final String teamName;

  @override
  State<SentTeamInvitationsPage> createState() =>
      _SentTeamInvitationsPageState();
}

class _SentTeamInvitationsPageState extends State<SentTeamInvitationsPage> {
  late final TeamRepository repository = TeamRepository(widget.auth.api);
  late Future<List<SentTeamInvitation>> invitations =
      repository.outgoingInvitations(widget.teamId);
  String? busyInvitation;

  Future<void> refresh() async {
    setState(() {
      invitations = repository.outgoingInvitations(widget.teamId);
    });
    try {
      await invitations;
    } catch (_) {
      // Keep the error state visible for retry.
    }
  }

  Future<void> revoke(SentTeamInvitation invitation) async {
    if (busyInvitation != null) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('لغو دعوت'),
        content: Text('دعوت برای ${invitation.recipientName} لغو شود؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('لغو دعوت'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() => busyInvitation = invitation.id);
    try {
      await repository.revokeInvitation(widget.teamId, invitation.id);
      if (mounted) {
        setState(
            () => invitations = repository.outgoingInvitations(widget.teamId));
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(_invitationError(error))),
        );
      }
    } finally {
      if (mounted) setState(() => busyInvitation = null);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text('دعوت‌های ارسالی · ${widget.teamName}')),
        body: SafeArea(
          child: FutureBuilder<List<SentTeamInvitation>>(
            future: invitations,
            builder: (context, snapshot) {
              if (!snapshot.hasData &&
                  snapshot.connectionState != ConnectionState.done) {
                return const NexoLoading(message: 'در حال دریافت دعوت‌ها...');
              }
              if (snapshot.hasError || !snapshot.hasData) {
                final error =
                    snapshot.error ?? StateError('Invitations unavailable');
                return NexoErrorView(
                  message: _invitationError(error),
                  offline: ApiClient.isNetworkFailure(error),
                  onRetry: refresh,
                );
              }
              final values = snapshot.data!;
              if (values.isEmpty) {
                return const NexoEmptyView(
                  title: 'دعوت ارسالی نداری',
                  message:
                      'دعوت‌هایی که برای بازیکنان فرستاده‌ای اینجا نمایش داده می‌شوند.',
                  icon: Icons.outbox_outlined,
                );
              }
              return RefreshIndicator(
                onRefresh: refresh,
                child: ListView.separated(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  itemCount: values.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (context, index) {
                    final invitation = values[index];
                    final busy = busyInvitation == invitation.id;
                    return Card(
                      child: ListTile(
                        leading: const Icon(Icons.person_outline),
                        title: Text(invitation.recipientName),
                        subtitle: Text(_sentStatus(invitation)),
                        trailing: invitation.isPending
                            ? IconButton(
                                tooltip: 'لغو دعوت',
                                onPressed:
                                    busy ? null : () => revoke(invitation),
                                icon: busy
                                    ? const SizedBox.square(
                                        dimension: 20,
                                        child: CircularProgressIndicator(
                                            strokeWidth: 2),
                                      )
                                    : const Icon(Icons.cancel_outlined),
                              )
                            : null,
                      ),
                    );
                  },
                ),
              );
            },
          ),
        ),
      );

  String _sentStatus(SentTeamInvitation invitation) {
    if (invitation.revokedAt != null) return 'لغوشده';
    if (invitation.decision == 'CODE') {
      final maximum = invitation.maxUses?.toString() ?? 'بدون محدودیت';
      return 'دعوت با کد · ${invitation.usesCount} از $maximum استفاده';
    }
    return switch (invitation.decision) {
      'PENDING' => 'در انتظار پاسخ',
      'ACCEPTED' => 'پذیرفته‌شده',
      'DECLINED' => 'ردشده',
      'EXPIRED' => 'منقضی‌شده',
      _ => 'وضعیت نامشخص',
    };
  }
}

class InvitePlayerDialog extends StatefulWidget {
  const InvitePlayerDialog({
    required this.repository,
    required this.teamId,
    super.key,
  });

  final TeamRepository repository;
  final String teamId;

  @override
  State<InvitePlayerDialog> createState() => _InvitePlayerDialogState();
}

class _InvitePlayerDialogState extends State<InvitePlayerDialog> {
  final controller = TextEditingController();
  Map<String, dynamic>? player;
  String? error;
  bool busy = false;

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  Future<void> search() async {
    final id = controller.text.trim();
    if (id.isEmpty || busy) return;
    setState(() {
      busy = true;
      error = null;
      player = null;
    });
    try {
      player = await widget.repository.findPlayer(id);
    } catch (failure) {
      error = _invitationError(failure);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> invite() async {
    final found = player;
    if (found == null || busy) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('ارسال دعوت؟'),
        content: Text(
            'برای ${found['name'] as String? ?? 'این بازیکن'} دعوت تیم فرستاده شود؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('بازگشت'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('ارسال دعوت'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.repository
          .invitePlayer(widget.teamId, found['id'] as String);
      if (mounted) Navigator.of(context).pop(true);
    } catch (failure) {
      if (mounted) setState(() => error = _invitationError(failure));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(
          left: 20,
          right: 20,
          top: 8,
          bottom: MediaQuery.viewInsetsOf(context).bottom + 20,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(children: [
              const Expanded(
                child: Text('دعوت بازیکن',
                    style:
                        TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              ),
              IconButton(
                tooltip: 'بستن',
                onPressed: busy ? null : () => Navigator.pop(context),
                icon: const Icon(Icons.close),
              ),
            ]),
            const Text('شناسه عمومی بازیکن را وارد کنید.',
                style: TextStyle(color: NexoTheme.muted)),
            const SizedBox(height: 14),
            TextField(
              controller: controller,
              autofocus: true,
              textDirection: TextDirection.ltr,
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => search(),
              decoration: InputDecoration(
                labelText: 'Public Player ID',
                suffixIcon: IconButton(
                  tooltip: 'جست‌وجو',
                  onPressed: busy ? null : search,
                  icon: const Icon(Icons.search),
                ),
              ),
            ),
            if (busy) ...[
              const SizedBox(height: 12),
              const LinearProgressIndicator(),
            ],
            if (player != null) ...[
              const SizedBox(height: 12),
              Card(
                child: ListTile(
                  leading: const CircleAvatar(
                    backgroundColor: NexoTheme.surfaceRaised,
                    child: Icon(Icons.person_outline),
                  ),
                  title: Text(player!['name'] as String? ?? 'بازیکن'),
                  subtitle: Text('شناسه عمومی: ${controller.text.trim()}',
                      textDirection: TextDirection.ltr),
                ),
              ),
              FilledButton.icon(
                onPressed: busy ? null : invite,
                icon: const Icon(Icons.person_add_alt_1),
                label: const Text('ارسال دعوت'),
              ),
            ],
            if (error != null) ...[
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: NexoTheme.danger.withValues(alpha: .1),
                  borderRadius: BorderRadius.circular(NexoTheme.radiusSmall),
                ),
                child: Text(error!,
                    style:
                        TextStyle(color: Theme.of(context).colorScheme.error)),
              ),
            ],
            const SizedBox(height: 8),
          ],
        ),
      );
}

String _invitationError(Object error) {
  if (ApiClient.isNetworkFailure(error)) {
    return 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کنید.';
  }
  if (error is ApiError) {
    return switch (error.code) {
      'TEAM_FULL' => 'ظرفیت تیم تکمیل است؛ حداکثر ۸ عضو می‌توانند عضو باشند.',
      'ALREADY_TEAM_MEMBER' => 'این بازیکن هم‌اکنون عضو تیم است.',
      'INVITE_EXPIRED' => 'این دعوت منقضی شده است.',
      'INVITE_REVOKED' => 'این دعوت لغو شده است.',
      'INVITATION_ALREADY_PENDING' => 'برای این بازیکن دعوت معلق وجود دارد.',
      'AUTHORIZATION_DENIED' ||
      'AUTHORIZATION_REQUIRED' =>
        'فقط Captain مجاز به دعوت بازیکن است.',
      'RESOURCE_NOT_FOUND' => 'بازیکن یا دعوت پیدا نشد.',
      _ => error.userMessage,
    };
  }
  return 'عملیات دعوت انجام نشد. دوباره تلاش کنید.';
}
