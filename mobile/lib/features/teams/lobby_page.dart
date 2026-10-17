import 'dart:async';

import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_routes.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import '../runtime/gameplay_page.dart';
import 'team_page.dart';
import 'team_repository.dart';

class LobbyPage extends StatefulWidget {
  const LobbyPage(
      {required this.auth,
      this.lobbyRepository,
      this.teamRepository,
      super.key});
  final AuthController auth;
  final LobbyRepository? lobbyRepository;
  final TeamRepository? teamRepository;

  @override
  State<LobbyPage> createState() => _LobbyPageState();
}

class _LobbyPageState extends State<LobbyPage> with WidgetsBindingObserver {
  late final LobbyRepository lobby;
  late final TeamRepository teamsRepository;
  late Future<_LobbyData> content;
  _LobbyData? lastData;
  Timer? lobbyPoll;
  final Map<String, String> registrationKeys = {};
  bool busy = false;
  bool refreshing = false;
  bool gameplayOpened = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    lobby = widget.lobbyRepository ?? LobbyRepository(widget.auth.api);
    teamsRepository = widget.teamRepository ?? TeamRepository(widget.auth.api);
    content = load();
    lobbyPoll = Timer.periodic(const Duration(seconds: 15), (_) {
      if (mounted) refresh();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    lobbyPoll?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      refresh();
    }
  }

  Future<_LobbyData> load() async {
    final values =
        await Future.wait([lobby.sessions(), teamsRepository.list()]);
    final sessions = values[0] as List<LobbySession>;
    final teams = values[1] as List<NexoTeam>;
    return _LobbyData(sessions, teams);
  }

  Future<void> refresh() async {
    if (refreshing) return;
    refreshing = true;
    setState(() => content = load());
    try {
      await content;
    } catch (_) {}
    refreshing = false;
  }

  Future<void> register(LobbySession session, NexoTeam team) async {
    if (busy) return;
    final key = '${session.id}:${team.id}';
    final idempotency =
        registrationKeys.putIfAbsent(key, () => const Uuid().v4());
    setState(() => busy = true);
    try {
      await lobby.registerTeam(session.id, team.id, idempotency);
      registrationKeys.remove(key);
      await refresh();
    } catch (error) {
      if (mounted) _notice(lobbyError(error));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> setReady(LobbySession session, bool ready) async {
    if (busy) return;
    setState(() => busy = true);
    try {
      await lobby.ready(session.id, ready);
      await refresh();
    } catch (error) {
      if (mounted) _notice(lobbyError(error));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  void _notice(String message) => ScaffoldMessenger.of(context)
      .showSnackBar(SnackBar(content: Text(message)));

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: const Text('لابی بازی'),
          leading: IconButton(
            tooltip: 'بازگشت به خانه',
            icon: const Icon(Icons.arrow_back),
            onPressed: () => Navigator.of(context)
                .pushNamedAndRemoveUntil(NexoRoutes.home, (_) => false),
          ),
          actions: [
            IconButton(
                tooltip: 'تازه‌سازی',
                onPressed: refresh,
                icon: const Icon(Icons.refresh))
          ],
        ),
        body: SafeArea(
          child: FutureBuilder<_LobbyData>(
            future: content,
            builder: (context, snapshot) {
              final data = snapshot.data ?? lastData;
              if (snapshot.connectionState != ConnectionState.done &&
                  data == null) {
                return const NexoLoading(message: 'در حال بررسی نشست‌ها…');
              }
              if (snapshot.hasError && data == null) {
                final offline = ApiClient.isNetworkFailure(snapshot.error!);
                return NexoErrorView(
                  message: offline
                      ? 'برای مشاهده لابی به اینترنت وصل شوید.'
                      : lobbyError(snapshot.error!),
                  offline: offline,
                  onRetry: refresh,
                );
              }
              if (snapshot.hasData) lastData = snapshot.data;
              final currentData = data!;
              final userId = widget.auth.user?['id'] as String? ?? '';
              final visibleSessions = currentData.sessions;
              final entering = visibleSessions
                  .where((session) =>
                      session.status == 'RUNNING' &&
                      _ownSessionTeam(session, userId) != null)
                  .firstOrNull;
              final ownRegistration =
                  entering == null ? null : _ownSessionTeam(entering, userId);
              if (entering != null &&
                  entering.status == 'RUNNING' &&
                  ownRegistration != null &&
                  !gameplayOpened) {
                gameplayOpened = true;
                WidgetsBinding.instance.addPostFrameCallback((_) {
                  if (mounted) _openGameplay(entering, ownRegistration);
                });
              }
              return RefreshIndicator(
                onRefresh: refresh,
                child: ListView(
                  padding: const EdgeInsets.all(NexoSpace.x4),
                  children: [
                    const NexoNotice(
                        'وضعیت نشست و آمادگی از سرور خوانده می‌شود. آغاز رسمی بازی فقط با GameMaster است.'),
                    if (currentData.teams.isEmpty)
                      Card(
                        child: Padding(
                          padding: const EdgeInsets.all(NexoSpace.x4),
                          child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                const Text(
                                    'برای ثبت‌نام ابتدا عضو یک تیم واجد شرایط شوید.'),
                                const SizedBox(height: NexoSpace.x3),
                                OutlinedButton(
                                    onPressed: () => Navigator.push(
                                        context,
                                        MaterialPageRoute(
                                            builder: (_) =>
                                                TeamPage(auth: widget.auth))),
                                    child: const Text('رفتن به تیم من')),
                              ]),
                        ),
                      ),
                    if (visibleSessions.isEmpty)
                      const NexoEmptyView(
                        title: 'نشستی برای نمایش پیدا نشد',
                        message:
                            'وقتی نشست قابل‌دسترسی برای حساب شما وجود داشته باشد، اینجا نمایش داده می‌شود.',
                        icon: Icons.meeting_room_outlined,
                      ),
                    if (snapshot.hasError)
                      const NexoNotice(
                          'به‌روزرسانی لابی انجام نشد؛ اطلاعات نمایش‌داده‌شده آخرین وضعیت دریافت‌شده است.'),
                    for (final session in visibleSessions)
                      _sessionCard(session, currentData.teams, userId),
                    if (busy)
                      const Padding(
                          padding: EdgeInsets.all(20),
                          child: Center(child: CircularProgressIndicator())),
                  ],
                ),
              );
            },
          ),
        ),
      );

  Widget _sessionCard(
      LobbySession session, List<NexoTeam> teams, String userId) {
    final registered = _ownSessionTeam(session, userId);
    final myTeams = teams
        .where((team) => team.members.any((member) => member.userId == userId))
        .toList();
    final captainTeam = myTeams
        .where((team) =>
            team.captainUserId == userId &&
            team.eligibleForGame &&
            !session.teams.any((entry) =>
                entry.teamId == team.id &&
                !['REMOVED', 'DISQUALIFIED'].contains(entry.status)))
        .firstOrNull;
    final alreadyRegistered = myTeams.any((team) => session.teams.any((entry) =>
        entry.teamId == team.id &&
        !['REMOVED', 'DISQUALIFIED'].contains(entry.status)));
    final player =
        registered?.players.where((item) => item.userId == userId).firstOrNull;
    final canReady = registered != null &&
        player != null &&
        ['REGISTRATION', 'READY'].contains(session.status) &&
        ['JOINED', 'READY'].contains(player.status);
    final ready = player?.status == 'READY';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(NexoSpace.x4),
        child:
            Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            const Icon(Icons.sports_esports_outlined, color: NexoTheme.amber),
            const SizedBox(width: NexoSpace.x2),
            Expanded(
                child: Text(
                    'نشست ${session.id.substring(0, session.id.length < 8 ? session.id.length : 8)}',
                    style: Theme.of(context).textTheme.titleLarge)),
            Chip(label: Text(sessionStatus(session.status))),
          ]),
          if (session.registrationClosesAt != null)
            Text('پایان ثبت‌نام: ${session.registrationClosesAt}',
                style: const TextStyle(color: NexoTheme.muted)),
          const SizedBox(height: NexoSpace.x2),
          for (final team in session.teams)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: NexoSpace.x1),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                        '${team.name} — ${registrationStatus(team.status)} (${team.playerCount} بازیکن)'),
                    for (final member in team.players)
                      Text('  ${member.name} · ${playerStatus(member.status)}',
                          style: const TextStyle(color: NexoTheme.muted)),
                  ]),
            ),
          if (session.status == 'REGISTRATION' &&
              captainTeam != null &&
              registered == null) ...[
            const SizedBox(height: NexoSpace.x3),
            FilledButton.icon(
              onPressed: busy ? null : () => register(session, captainTeam),
              icon: const Icon(Icons.how_to_reg),
              label: Text('ثبت‌نام ${captainTeam.name} در نشست'),
            ),
          ],
          if (alreadyRegistered && registered == null)
            const NexoNotice(
                'تیم شما در این نشست ثبت است؛ وضعیت فهرست تیم در نشست نمایش داده می‌شود.'),
          if (canReady)
            OutlinedButton.icon(
              onPressed: busy ? null : () => setReady(session, !ready),
              icon: Icon(
                  ready ? Icons.check_circle : Icons.radio_button_unchecked),
              label: Text(
                  ready ? 'لغو اعلام آمادگی' : 'اعلام آمادگی با این دستگاه'),
            ),
          if (session.status == 'RUNNING' && registered != null)
            FilledButton.icon(
              onPressed: () => _openGameplay(session, registered),
              icon: const Icon(Icons.play_arrow),
              label: const Text('ورود به بازی'),
            ),
          if (session.status == 'PAUSED' && registered != null)
            const NexoNotice(
                'نشست موقتاً متوقف است؛ وضعیت بازی از سرور پیگیری می‌شود.'),
        ]),
      ),
    );
  }

  SessionTeam? _ownSessionTeam(LobbySession session, String userId) => session
      .teams
      .where((team) => team.players.any((player) =>
          player.userId == userId &&
          !['REMOVED', 'DISQUALIFIED', 'FINISHED'].contains(player.status)))
      .firstOrNull;

  void _openGameplay(LobbySession session, SessionTeam team) {
    gameplayOpened = true;
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => GameplayPage(
          sessionId: session.id, gameName: 'بازی NEXO', teamName: team.name),
    ));
  }
}

class _LobbyData {
  const _LobbyData(this.sessions, this.teams);
  final List<LobbySession> sessions;
  final List<NexoTeam> teams;
}

String sessionStatus(String status) => switch (status) {
      'REGISTRATION' => 'ثبت‌نام',
      'READY' => 'آماده شروع',
      'RUNNING' => 'در حال اجرا',
      'PAUSED' => 'متوقف موقت',
      'FINISHED' => 'پایان‌یافته',
      'CANCELLED' => 'لغوشده',
      'DRAFT' => 'پیش‌نویس',
      _ => status,
    };

String registrationStatus(String status) => switch (status) {
      'REGISTERED' => 'ثبت‌شده',
      'READY' => 'آماده',
      'ACTIVE' => 'فعال',
      'FINISHED' => 'پایان‌یافته',
      'REMOVED' => 'حذف‌شده',
      'DISQUALIFIED' => 'واجد شرایط نیست',
      _ => status,
    };

String playerStatus(String status) => switch (status) {
      'JOINED' => 'آماده‌نشده',
      'READY' => 'آماده',
      'ACTIVE' => 'در بازی',
      'FINISHED' => 'پایان‌یافته',
      'REMOVED' => 'خارج‌شده',
      'DISQUALIFIED' => 'واجد شرایط نیست',
      _ => status,
    };

String lobbyError(Object error) {
  final code = error is ApiError ? error.code : '';
  return switch (code) {
    'SESSION_NOT_OPEN' ||
    'STATE_TRANSITION_INVALID' =>
      'ثبت‌نام این نشست باز نیست.',
    'SESSION_TEAM_CAPACITY_REACHED' => 'ظرفیت نشست (۵ تیم) تکمیل شده است.',
    'TEAM_NOT_ELIGIBLE' => 'تیم باید ۴ تا ۸ عضو فعال داشته باشد.',
    'CONFLICT' => 'این تیم قبلاً ثبت شده یا در نشست تعارض عضویت دارد.',
    'AUTHORIZATION_DENIED' ||
    'AUTHORIZATION_REQUIRED' =>
      'ثبت‌نام نشست فقط برای کاپیتان تیم مجاز است.',
    'DEVICE_NOT_BOUND' => 'اتصال دستگاه به نشست تأیید نشد؛ دوباره تلاش کنید.',
    'SESSION_NOT_READY' =>
      'برای آماده‌شدن، دست‌کم چهار بازیکن باید با دستگاه متصل آماده باشند.',
    _ => error is ApiError
        ? error.userMessage
        : 'دریافت یا ثبت وضعیت لابی ناموفق بود؛ دوباره تلاش کنید.',
  };
}
