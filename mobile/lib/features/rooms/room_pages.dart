import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';
import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import '../teams/team_repository.dart';
import 'room_repository.dart';

class RoomListPage extends StatefulWidget {
  const RoomListPage({required this.auth, required this.game, super.key});

  final AuthController auth;
  final Map<String, dynamic> game;

  @override
  State<RoomListPage> createState() => _RoomListPageState();
}

class _RoomListPageState extends State<RoomListPage> {
  late final RoomRepository repository = RoomRepository(widget.auth.api);
  late final TeamRepository teamRepository = TeamRepository(widget.auth.api);
  late Future<List<GameRoomSummary>> rooms = _load();
  late Future<List<NexoTeam>> myTeams = _loadTeams();

  Future<List<GameRoomSummary>> _load() =>
      repository.list(widget.game['id'] as String);

  Future<List<NexoTeam>> _loadTeams() => teamRepository.list();

  Future<void> _refresh() async {
    setState(() {
      rooms = _load();
    });
    try {
      await rooms;
    } catch (_) {
      // The room error state shows the retry action.
    }
  }

  Future<void> _refreshTeams() async {
    setState(() {
      myTeams = _loadTeams();
    });
    try {
      await myTeams;
    } catch (_) {
      // Team loading has its own retry state.
    }
  }

  Future<void> _createRoom(List<NexoTeam> eligibleTeams) async {
    final created = await showDialog<GameRoomSummary>(
      context: context,
      builder: (_) => _CreateRoomDialog(
        repository: repository,
        gameId: widget.game['id'] as String,
        teams: eligibleTeams,
      ),
    );
    if (created != null && mounted) {
      await _refresh();
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: Text(widget.game['name'] as String? ?? 'اتاق‌های بازی'),
        ),
        body: SafeArea(
          child: FutureBuilder<List<GameRoomSummary>>(
            future: rooms,
            builder: (context, snapshot) {
              if (!snapshot.hasData &&
                  snapshot.connectionState != ConnectionState.done) {
                return const NexoLoading(message: 'در حال دریافت اتاق‌ها…');
              }
              if (snapshot.hasError && !snapshot.hasData) {
                final offline = ApiClient.isNetworkFailure(snapshot.error!);
                return NexoErrorView(
                  message: offline
                      ? 'برای دریافت اتاق‌ها به اینترنت وصل شوید.'
                      : _apiMessage(snapshot.error!, subject: 'اتاق‌ها'),
                  offline: offline,
                  onRetry: _refresh,
                );
              }

              final availableRooms = snapshot.data ?? const [];
              return RefreshIndicator(
                onRefresh: _refresh,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  children: [
                    _MyTeamsPanel(
                      future: myTeams,
                      userId: widget.auth.user?['id'] as String? ?? '',
                      onRetry: _refreshTeams,
                      onCreate: _createRoom,
                    ),
                    const SizedBox(height: 12),
                    Text('اتاق‌های بازی',
                        style: Theme.of(context).textTheme.titleLarge),
                    const SizedBox(height: 8),
                    if (availableRooms.isEmpty)
                      const NexoEmptyView(
                        title: 'اتاقی پیدا نشد',
                        message: 'هنوز اتاقی برای این بازی ساخته نشده است.',
                        icon: Icons.meeting_room_outlined,
                      ),
                    for (final room in availableRooms)
                      _RoomCard(
                        room: room,
                        onTap: () => Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => RoomDetailPage(
                              auth: widget.auth,
                              roomId: room.id,
                              repository: repository,
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              );
            },
          ),
        ),
      );
}

class _MyTeamsPanel extends StatelessWidget {
  const _MyTeamsPanel({
    required this.future,
    required this.userId,
    required this.onRetry,
    required this.onCreate,
  });

  final Future<List<NexoTeam>> future;
  final String userId;
  final Future<void> Function() onRetry;
  final Future<void> Function(List<NexoTeam>) onCreate;

  @override
  Widget build(BuildContext context) => FutureBuilder<List<NexoTeam>>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done &&
              !snapshot.hasData) {
            return const Card(
              child: ListTile(
                leading: SizedBox.square(
                  dimension: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
                title: Text('در حال بررسی تیم‌های شما…'),
              ),
            );
          }
          if (snapshot.hasError && !snapshot.hasData) {
            return Card(
              child: ListTile(
                title: const Text('فهرست تیم‌های شما دریافت نشد.'),
                trailing: IconButton(
                  tooltip: 'تلاش دوباره',
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh),
                ),
              ),
            );
          }

          final eligibleTeams = (snapshot.data ?? const <NexoTeam>[])
              .where((team) =>
                  team.captainUserId == userId && team.eligibleForGame)
              .toList(growable: false);
          return Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('ساخت اتاق',
                      style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 4),
                  Text(
                    eligibleTeams.isEmpty
                        ? 'برای ساخت اتاق، سرپرست یک تیم فعال با ۴ تا ۸ عضو باشید.'
                        : 'تیم سرپرستی شما همراه ساخت اتاق ثبت می‌شود.',
                    style: const TextStyle(color: NexoTheme.muted),
                  ),
                  if (eligibleTeams.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    FilledButton.icon(
                      onPressed: () => onCreate(eligibleTeams),
                      icon: const Icon(Icons.add_home_work_outlined),
                      label: const Text('ساخت اتاق'),
                    ),
                  ],
                ],
              ),
            ),
          );
        },
      );
}

class _RoomCard extends StatelessWidget {
  const _RoomCard({required this.room, required this.onTap});

  final GameRoomSummary room;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final status = _roomStatus(room.status, room.sessionStatus);
    final remaining = (room.capacity - room.teamCount).clamp(0, room.capacity);
    return Card(
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: NexoTheme.surfaceRaised,
          child: Icon(status.icon, color: status.color),
        ),
        title: Text(room.name, maxLines: 1, overflow: TextOverflow.ellipsis),
        subtitle: Text(
          '${status.label}  •  ${_faNumber(room.teamCount)} از ${_faNumber(room.capacity)} تیم'
          '${remaining == 0 ? '' : '  •  ${_faNumber(remaining)} جای خالی'}',
        ),
        trailing: const Icon(Icons.chevron_left),
        onTap: onTap,
      ),
    );
  }
}

class RoomDetailPage extends StatefulWidget {
  const RoomDetailPage({
    required this.auth,
    required this.roomId,
    required this.repository,
    super.key,
  });

  final AuthController auth;
  final String roomId;
  final RoomRepository repository;

  @override
  State<RoomDetailPage> createState() => _RoomDetailPageState();
}

class _RoomDetailPageState extends State<RoomDetailPage> {
  late Future<GameRoomSummary> room = _load();
  late final TeamRepository teamRepository = TeamRepository(widget.auth.api);
  late Future<List<NexoTeam>> myTeams = teamRepository.list();
  final Map<String, String> addTeamKeys = {};
  bool joining = false;
  bool addingTeam = false;

  Future<GameRoomSummary> _load() => widget.repository.show(widget.roomId);

  Future<void> _refresh() async {
    setState(() {
      room = _load();
    });
    try {
      await room;
    } catch (_) {
      // The room error state shows the retry action.
    }
  }

  Future<void> _refreshTeams() async {
    setState(() {
      myTeams = teamRepository.list();
    });
    try {
      await myTeams;
    } catch (_) {
      // The team panel has its own retry action.
    }
  }

  Future<void> _addTeamToRoom(
      GameRoomSummary currentRoom, List<NexoTeam> eligibleTeams) async {
    final availableTeams = eligibleTeams
        .where((team) =>
            team.captainUserId == (widget.auth.user?['id'] as String? ?? '') &&
            team.eligibleForGame &&
            !currentRoom.teams.any((roomTeam) => roomTeam['id'] == team.id))
        .toList(growable: false);
    if (availableTeams.isEmpty) return;

    final team = await showDialog<NexoTeam>(
      context: context,
      builder: (_) => _TeamPickerDialog(teams: availableTeams),
    );
    if (team == null || !mounted) return;

    final key = addTeamKeys.putIfAbsent(team.id, const Uuid().v4);
    setState(() => addingTeam = true);
    try {
      await widget.repository.addTeam(currentRoom.id, team.id, key);
      addTeamKeys.remove(team.id);
      await _refresh();
      await _refreshTeams();
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(_apiMessage(error, subject: 'افزودن تیم'))),
        );
      }
    } finally {
      if (mounted) setState(() => addingTeam = false);
    }
  }

  Future<void> _joinWithInvite() async {
    final inviteCode = await showDialog<String>(
      context: context,
      builder: (context) => const _InviteCodeDialog(),
    );
    if (inviteCode == null || !mounted) return;

    setState(() => joining = true);
    try {
      final team = await TeamRepository(widget.auth.api).join(inviteCode);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('به تیم «${team.name}» پیوستید.')),
      );
      await _refresh();
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(_apiMessage(error, subject: 'عضویت'))),
      );
    } finally {
      if (mounted) setState(() => joining = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('تیم‌های اتاق')),
        body: SafeArea(
          child: FutureBuilder<GameRoomSummary>(
            future: room,
            builder: (context, snapshot) {
              if (!snapshot.hasData &&
                  snapshot.connectionState != ConnectionState.done) {
                return const NexoLoading(
                    message: 'در حال دریافت اطلاعات اتاق…');
              }
              if (snapshot.hasError || !snapshot.hasData) {
                final failure =
                    snapshot.error ?? StateError('Room unavailable');
                final offline = ApiClient.isNetworkFailure(failure);
                return NexoErrorView(
                  message: offline
                      ? 'برای دریافت تیم‌ها به اینترنت وصل شوید.'
                      : _apiMessage(failure, subject: 'تیم‌ها'),
                  offline: offline,
                  onRetry: _refresh,
                );
              }

              final value = snapshot.data!;
              final roomStatus = _roomStatus(value.status, value.sessionStatus);
              final mayJoinTeam = value.status == 'OPEN' &&
                  value.teamCount < value.capacity &&
                  (value.sessionStatus == null ||
                      value.sessionStatus == 'REGISTRATION');
              return RefreshIndicator(
                onRefresh: _refresh,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  children: [
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(20),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(value.gameName,
                                style: const TextStyle(
                                    color: NexoTheme.amberSoft,
                                    fontWeight: FontWeight.w700)),
                            const SizedBox(height: 8),
                            Text(value.name,
                                style: Theme.of(context).textTheme.titleLarge),
                            if (value.city?.trim().isNotEmpty == true) ...[
                              const SizedBox(height: 4),
                              Text(value.city!,
                                  style:
                                      const TextStyle(color: NexoTheme.muted)),
                            ],
                            const SizedBox(height: 12),
                            _StatusChip(
                                label: roomStatus.label,
                                color: roomStatus.color),
                            const SizedBox(height: 8),
                            Text(
                                '${_faNumber(value.teamCount)} از ${_faNumber(value.capacity)} تیم'),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text('تیم‌های حاضر',
                        style: Theme.of(context).textTheme.titleLarge),
                    const SizedBox(height: 8),
                    if (value.teams.isEmpty)
                      const NexoEmptyView(
                        title: 'تیمی در اتاق نیست',
                        message: 'هنوز تیمی به این اتاق نپیوسته است.',
                        icon: Icons.groups_outlined,
                      ),
                    for (final team in value.teams) _TeamCard(team: team),
                    _TeamsForRoomPanel(
                      future: myTeams,
                      room: value,
                      userId: widget.auth.user?['id'] as String? ?? '',
                      adding: addingTeam,
                      onRetry: _refreshTeams,
                      onAdd: (teams) => _addTeamToRoom(value, teams),
                    ),
                    if (mayJoinTeam) ...[
                      const SizedBox(height: 8),
                      FilledButton.icon(
                        onPressed: joining ? null : _joinWithInvite,
                        icon: joining
                            ? const SizedBox.square(
                                dimension: 20,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    color: NexoTheme.background),
                              )
                            : const Icon(Icons.key_outlined),
                        label: Text(joining
                            ? 'در حال بررسی کد دعوت…'
                            : 'عضویت در تیم با کد دعوت'),
                      ),
                      const Padding(
                        padding: EdgeInsets.only(top: 8),
                        child: Text(
                          'برای عضویت، کد دعوت تیم را از سرپرست آن بگیرید.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: NexoTheme.muted),
                        ),
                      ),
                    ],
                  ],
                ),
              );
            },
          ),
        ),
      );
}

class _TeamsForRoomPanel extends StatelessWidget {
  const _TeamsForRoomPanel({
    required this.future,
    required this.room,
    required this.userId,
    required this.adding,
    required this.onRetry,
    required this.onAdd,
  });

  final Future<List<NexoTeam>> future;
  final GameRoomSummary room;
  final String userId;
  final bool adding;
  final Future<void> Function() onRetry;
  final Future<void> Function(List<NexoTeam>) onAdd;

  @override
  Widget build(BuildContext context) => FutureBuilder<List<NexoTeam>>(
        future: future,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done &&
              !snapshot.hasData) {
            return const Card(
              child: ListTile(
                leading: SizedBox.square(
                  dimension: 20,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
                title: Text('در حال بررسی تیم‌های شما…'),
              ),
            );
          }
          if (snapshot.hasError && !snapshot.hasData) {
            return Card(
              child: ListTile(
                title: const Text('فهرست تیم‌های شما دریافت نشد.'),
                trailing: IconButton(
                  tooltip: 'تلاش دوباره',
                  onPressed: onRetry,
                  icon: const Icon(Icons.refresh),
                ),
              ),
            );
          }

          final available = (snapshot.data ?? const <NexoTeam>[])
              .where((team) =>
                  team.captainUserId == userId &&
                  team.eligibleForGame &&
                  !room.teams.any((entry) => entry['id'] == team.id))
              .toList(growable: false);
          final roomAcceptsTeams = room.status == 'OPEN' &&
              room.teamCount < room.capacity &&
              (room.sessionStatus == null ||
                  room.sessionStatus == 'REGISTRATION');
          if (!roomAcceptsTeams || available.isEmpty) {
            return const SizedBox.shrink();
          }
          return Padding(
            padding: const EdgeInsets.only(top: 8),
            child: FilledButton.icon(
              onPressed: adding ? null : () => onAdd(available),
              icon: adding
                  ? const SizedBox.square(
                      dimension: 20,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: NexoTheme.background),
                    )
                  : const Icon(Icons.group_add_outlined),
              label: Text(adding ? 'در حال افزودن تیم…' : 'افزودن تیم خودم'),
            ),
          );
        },
      );
}

class _CreateRoomDialog extends StatefulWidget {
  const _CreateRoomDialog({
    required this.repository,
    required this.gameId,
    required this.teams,
  });

  final RoomRepository repository;
  final String gameId;
  final List<NexoTeam> teams;

  @override
  State<_CreateRoomDialog> createState() => _CreateRoomDialogState();
}

class _CreateRoomDialogState extends State<_CreateRoomDialog> {
  final formKey = GlobalKey<FormState>();
  final name = TextEditingController();
  final idempotencyKey = const Uuid().v4();
  NexoTeam? selectedTeam;
  bool submitting = false;
  String? error;

  @override
  void dispose() {
    name.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (submitting || !formKey.currentState!.validate()) return;
    final team = selectedTeam ?? widget.teams.firstOrNull;
    if (team == null) return;

    setState(() {
      submitting = true;
      error = null;
    });
    try {
      final room = await widget.repository.create(
        widget.gameId,
        name.text.trim(),
        team.id,
        idempotencyKey,
      );
      if (mounted) Navigator.of(context).pop(room);
    } catch (failure) {
      if (mounted) {
        setState(() => error = _apiMessage(failure, subject: 'ساخت اتاق'));
      }
    } finally {
      if (mounted) setState(() => submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('ساخت اتاق'),
        content: Form(
          key: formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextFormField(
                controller: name,
                autofocus: true,
                textDirection: TextDirection.rtl,
                textInputAction: TextInputAction.done,
                onFieldSubmitted: (_) => submit(),
                decoration: const InputDecoration(labelText: 'نام اتاق'),
                validator: (value) => value == null || value.trim().length < 2
                    ? 'نام اتاق باید دست‌کم ۲ نویسه باشد.'
                    : null,
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<NexoTeam>(
                initialValue: selectedTeam ?? widget.teams.firstOrNull,
                items: [
                  for (final team in widget.teams)
                    DropdownMenuItem(value: team, child: Text(team.name)),
                ],
                onChanged: submitting
                    ? null
                    : (team) => setState(() => selectedTeam = team),
                decoration: const InputDecoration(labelText: 'تیم سرپرستی شما'),
              ),
              if (error != null) ...[
                const SizedBox(height: 12),
                NexoNotice(error!,
                    icon: Icons.error_outline, color: NexoTheme.danger),
              ],
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: submitting ? null : () => Navigator.pop(context),
            child: const Text('انصراف'),
          ),
          FilledButton(
            onPressed: submitting ? null : submit,
            child: submitting
                ? const SizedBox.square(
                    dimension: 20,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: NexoTheme.background),
                  )
                : const Text('ساخت اتاق'),
          ),
        ],
      );
}

class _TeamPickerDialog extends StatelessWidget {
  const _TeamPickerDialog({required this.teams});

  final List<NexoTeam> teams;

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('انتخاب تیم'),
        content: SizedBox(
          width: double.maxFinite,
          child: ListView(
            shrinkWrap: true,
            children: [
              for (final team in teams)
                ListTile(
                  title: Text(team.name),
                  subtitle:
                      Text('اعضا: ${_faNumber(team.members.length)} از ۸ نفر'),
                  onTap: () => Navigator.of(context).pop(team),
                ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('انصراف'),
          ),
        ],
      );
}

class _TeamCard extends StatelessWidget {
  const _TeamCard({required this.team});

  final Map<String, dynamic> team;

  @override
  Widget build(BuildContext context) {
    final members = (team['member_count'] as num?)?.toInt() ?? 0;
    final remaining = (8 - members).clamp(0, 8);
    final eligible = team['eligible'] == true;
    final canRecruit = remaining > 0 && team['actor_is_member'] != true;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              const Icon(Icons.groups_outlined, color: NexoTheme.amber),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  team['name'] as String? ?? 'تیم',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              if (eligible)
                const _StatusChip(
                    label: 'آماده بازی', color: NexoTheme.success),
            ]),
            const SizedBox(height: 8),
            Text('سرپرست: ${team['captain'] as String? ?? 'اعلام نشده'}'),
            const SizedBox(height: 4),
            Text('اعضا: ${_faNumber(members)} از ۸ نفر'),
            const SizedBox(height: 8),
            _StatusChip(
              label: canRecruit
                  ? 'ظرفیت خالی • ${_faNumber(remaining)} جای خالی'
                  : remaining == 0
                      ? 'ظرفیت تکمیل'
                      : 'عضوگیری غیرفعال',
              color: canRecruit ? NexoTheme.success : NexoTheme.muted,
            ),
          ],
        ),
      ),
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(
          color: color.withValues(alpha: .12),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: color.withValues(alpha: .55)),
        ),
        child: Text(label,
            style: TextStyle(color: color, fontWeight: FontWeight.w700)),
      );
}

class _InviteCodeDialog extends StatefulWidget {
  const _InviteCodeDialog();

  @override
  State<_InviteCodeDialog> createState() => _InviteCodeDialogState();
}

class _InviteCodeDialogState extends State<_InviteCodeDialog> {
  final controller = TextEditingController();

  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('کد دعوت تیم'),
        content: TextField(
          controller: controller,
          autofocus: true,
          textDirection: TextDirection.ltr,
          textInputAction: TextInputAction.done,
          onSubmitted: (_) => _submit(context),
          decoration: const InputDecoration(labelText: 'کد دعوت'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('بازگشت'),
          ),
          FilledButton(
            onPressed: () => _submit(context),
            child: const Text('عضویت'),
          ),
        ],
      );

  void _submit(BuildContext context) {
    final code = controller.text.trim();
    if (code.isNotEmpty) Navigator.pop(context, code);
  }
}

({String label, Color color, IconData icon}) _roomStatus(
    String status, String? sessionStatus) {
  if (['RUNNING', 'PAUSED', 'READY'].contains(sessionStatus) ||
      status == 'IN_GAME') {
    return (
      label: 'در حال بازی',
      color: NexoTheme.information,
      icon: Icons.sports_esports_outlined
    );
  }
  if (status == 'FULL') {
    return (
      label: 'ظرفیت تکمیل',
      color: NexoTheme.muted,
      icon: Icons.groups_outlined
    );
  }
  if (status == 'OPEN' &&
      (sessionStatus == null || sessionStatus == 'REGISTRATION')) {
    return (
      label: 'عضوپذیر',
      color: NexoTheme.success,
      icon: Icons.group_add_outlined
    );
  }
  return (label: 'بسته', color: NexoTheme.muted, icon: Icons.lock_outline);
}

String _apiMessage(Object error, {required String subject}) {
  if (error is ApiError) {
    return switch (error.code) {
      'AUTHENTICATION_REQUIRED' => 'برای ادامه دوباره وارد حساب شوید.',
      'AUTHORIZATION_DENIED' ||
      'AUTHORIZATION_REQUIRED' =>
        'اجازهٔ مشاهده یا عضویت در این مورد را ندارید.',
      'RESOURCE_NOT_FOUND' => '$subject پیدا نشد یا دیگر در دسترس نیست.',
      'TEAM_NOT_ELIGIBLE' => 'این تیم شرایط عضویت در بازی را ندارد.',
      'CONFLICT' => 'وضعیت تغییر کرده است؛ دوباره بارگیری کنید.',
      'ACTION_NOT_ALLOWED' => 'در حال حاضر امکان انجام این کار نیست.',
      'VALIDATION_FAILED' => 'کد دعوت معتبر نیست.',
      'RATE_LIMITED' => 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.',
      _ => 'دریافت $subject انجام نشد. دوباره تلاش کنید.',
    };
  }
  return 'دریافت $subject انجام نشد. دوباره تلاش کنید.';
}

String _faNumber(int value) => value.toString().replaceAllMapped(
      RegExp(r'[0-9]'),
      (match) => '۰۱۲۳۴۵۶۷۸۹'[int.parse(match[0]!)],
    );
