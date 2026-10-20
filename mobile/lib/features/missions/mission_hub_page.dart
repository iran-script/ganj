import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import '../runtime/gameplay_page.dart';
import '../scoring/game_result_page.dart';
import '../teams/lobby_page.dart';

class MissionHubPage extends StatefulWidget {
  const MissionHubPage(
      {required this.auth, this.openScanner = false, super.key});

  final AuthController auth;
  final bool openScanner;

  @override
  State<MissionHubPage> createState() => _MissionHubPageState();
}

class _MissionHubPageState extends State<MissionHubPage> {
  late Future<_MissionData> missions = _load();

  Future<_MissionData> _load() async {
    final values = await Future.wait([
      widget.auth.api.get('/game-sessions'),
      widget.auth.api.get('/games'),
    ]);
    final sessions = (values[0]['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .where((session) => _ownTeam(session, widget.auth.user?['id']) != null)
        .toList(growable: false);
    final games = (values[1]['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList(growable: false);
    return _MissionData(sessions, games);
  }

  Future<void> _refresh() async {
    setState(() => missions = _load());
    try {
      await missions;
    } catch (_) {
      // The error state provides a retry action.
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title:
              Text(widget.openScanner ? 'اسکن در مأموریت' : 'مأموریت‌های من'),
          actions: [
            IconButton(
              tooltip: 'تیم من',
              onPressed: () => Navigator.of(context).pushNamed('/teams'),
              icon: const Icon(Icons.groups_2_outlined),
            ),
          ],
        ),
        body: SafeArea(
          child: FutureBuilder<_MissionData>(
            future: missions,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done &&
                  !snapshot.hasData) {
                return const _MissionSkeleton();
              }
              if (snapshot.hasError && !snapshot.hasData) {
                final offline = ApiClient.isNetworkFailure(snapshot.error!);
                return NexoErrorView(
                  message: offline
                      ? 'برای دیدن مأموریت‌های فعال به اینترنت وصل شوید.'
                      : snapshot.error is ApiError
                          ? (snapshot.error! as ApiError).userMessage
                          : 'دریافت مأموریت‌ها انجام نشد.',
                  offline: offline,
                  onRetry: _refresh,
                );
              }

              final data = snapshot.data!;
              final active = data.sessions.where((session) =>
                  const ['RUNNING', 'PAUSED'].contains(session['status']));
              final scheduled = data.sessions.where((session) => !const [
                    'RUNNING',
                    'PAUSED',
                    'FINISHED',
                    'CANCELLED'
                  ].contains(session['status']));
              final completed = data.sessions
                  .where((session) => session['status'] == 'FINISHED');

              return RefreshIndicator(
                onRefresh: _refresh,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
                  children: [
                    _MissionHero(
                      scanMode: widget.openScanner,
                      activeCount: active.length,
                    ),
                    const SizedBox(height: 20),
                    if (active.isNotEmpty) ...[
                      const _SectionTitle(title: 'در حال اجرا', count: null),
                      const SizedBox(height: 10),
                      for (final session in active)
                        _MissionCard(
                          session: session,
                          game: _gameFor(data.games, session['game_id']),
                          team: _ownTeam(session, widget.auth.user?['id'])!,
                          onOpen: () => _openMission(session, data.games),
                          scanMode: widget.openScanner,
                        ),
                    ],
                    if (scheduled.isNotEmpty) ...[
                      const SizedBox(height: 18),
                      const _SectionTitle(
                          title: 'نوبت‌های پیش رو', count: null),
                      const SizedBox(height: 10),
                      for (final session in scheduled)
                        _MissionCard(
                          session: session,
                          game: _gameFor(data.games, session['game_id']),
                          team: _ownTeam(session, widget.auth.user?['id'])!,
                          onOpen: () => Navigator.of(context).push(
                            MaterialPageRoute<void>(
                              builder: (_) => LobbyPage(auth: widget.auth),
                            ),
                          ),
                          scanMode: widget.openScanner,
                        ),
                    ],
                    if (completed.isNotEmpty) ...[
                      const SizedBox(height: 18),
                      const _SectionTitle(title: 'نتایج قبلی', count: null),
                      const SizedBox(height: 10),
                      for (final session in completed)
                        _CompletedMissionCard(
                          session: session,
                          game: _gameFor(data.games, session['game_id']),
                          team: _ownTeam(session, widget.auth.user?['id'])!,
                        ),
                    ],
                    if (data.sessions.isEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 28),
                        child: NexoEmptyView(
                          icon: Icons.explore_outlined,
                          title: widget.openScanner
                              ? 'برای اسکن، وارد یک مأموریت شوید'
                              : 'هنوز مأموریتی ندارید',
                          message: widget.openScanner
                              ? 'کدهای QR هر زمان که در مرحله فعال بازی لازم باشند، داخل همان مأموریت اسکن می‌شوند.'
                              : 'از بازی‌های در دسترس یک نوبت انتخاب کنید و آماده شروع شوید.',
                        ),
                      ),
                    if (data.sessions.isEmpty) ...[
                      const SizedBox(height: 16),
                      FilledButton.icon(
                        onPressed: () =>
                            Navigator.of(context).pushNamed('/games'),
                        icon: const Icon(Icons.explore_outlined),
                        label: const Text('دیدن بازی‌ها'),
                      ),
                    ],
                    const SizedBox(height: 10),
                    _MapAccessCard(
                      enabled: active.isNotEmpty,
                      onTap: active.isEmpty
                          ? null
                          : () => _openMission(active.first, data.games),
                    ),
                  ],
                ),
              );
            },
          ),
        ),
        bottomNavigationBar: NexoBottomNavigation(
          selectedIndex: widget.openScanner ? 2 : 3,
        ),
      );

  void _openMission(
      Map<String, dynamic> session, List<Map<String, dynamic>> games) {
    HapticFeedback.selectionClick();
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => GameplayPage(
        sessionId: session['id'] as String,
        gameName: _gameFor(games, session['game_id'])?['name'] as String? ??
            'بازی NEXO',
        teamName:
            _ownTeam(session, widget.auth.user?['id'])?['name'] as String?,
      ),
    ));
  }
}

class _MissionData {
  const _MissionData(this.sessions, this.games);

  final List<Map<String, dynamic>> sessions;
  final List<Map<String, dynamic>> games;
}

class _MissionSkeleton extends StatelessWidget {
  const _MissionSkeleton();

  @override
  Widget build(BuildContext context) => ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Container(
            padding: const EdgeInsets.all(22),
            decoration: BoxDecoration(
              color: NexoTheme.surface,
              borderRadius: BorderRadius.circular(28),
            ),
            child: const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                NexoSkeleton(width: 175, height: 22),
                SizedBox(height: 14),
                NexoSkeleton(width: 245, height: 14),
                SizedBox(height: 8),
                NexoSkeleton(width: 195, height: 14),
              ],
            ),
          ),
          const SizedBox(height: 24),
          for (var index = 0; index < 2; index++) ...[
            const Card(
              child: Padding(
                padding: EdgeInsets.all(18),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(children: [
                      NexoSkeleton(width: 44, height: 44, radius: 14),
                      SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            NexoSkeleton(width: 160),
                            SizedBox(height: 10),
                            NexoSkeleton(width: 95, height: 12),
                          ],
                        ),
                      ),
                    ]),
                    SizedBox(height: 20),
                    NexoSkeleton(height: 48, radius: 16),
                  ],
                ),
              ),
            ),
          ],
        ],
      );
}

Map<String, dynamic>? _ownTeam(Map<String, dynamic> session, dynamic userId) =>
    (session['teams'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .where((team) => (team['players'] as List? ?? const [])
            .whereType<Map>()
            .any(
              (player) =>
                  (player['user'] as Map?)?['id'] == userId &&
                  !const ['REMOVED', 'DISQUALIFIED'].contains(player['status']),
            ))
        .firstOrNull;

Map<String, dynamic>? _gameFor(
        List<Map<String, dynamic>> games, dynamic gameId) =>
    games.where((game) => game['id'] == gameId).firstOrNull;

class _MissionHero extends StatelessWidget {
  const _MissionHero({required this.scanMode, required this.activeCount});

  final bool scanMode;
  final int activeCount;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(22),
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            begin: AlignmentDirectional.topStart,
            end: AlignmentDirectional.bottomEnd,
            colors: [Color(0xff30205E), NexoTheme.surface],
          ),
          borderRadius: BorderRadius.circular(28),
          border: Border.all(color: NexoTheme.primary.withValues(alpha: .45)),
          boxShadow: NexoTheme.cardShadow,
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                      scanMode ? 'نقطه بعدی را پیدا کن' : 'شهر، زمین بازی توست',
                      style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 8),
                  Text(
                    scanMode
                        ? 'برای اسکن QR، مأموریت فعال را ادامه بده.'
                        : activeCount == 0
                            ? 'تیمت را آماده کن و برای یک ماجراجویی ثبت‌نام کن.'
                            : 'مأموریت فعال تیم آماده ادامه است.',
                    style: const TextStyle(color: NexoTheme.muted, height: 1.5),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Container(
              width: 64,
              height: 64,
              decoration: BoxDecoration(
                color: NexoTheme.primary.withValues(alpha: .18),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Icon(
                scanMode ? Icons.qr_code_scanner : Icons.explore,
                color: scanMode ? NexoTheme.cyan : NexoTheme.amberSoft,
                size: 32,
              ),
            ),
          ],
        ),
      );
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle({required this.title, required this.count});

  final String title;
  final int? count;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          if (count != null) ...[
            const SizedBox(width: 8),
            Text('$count', style: const TextStyle(color: NexoTheme.muted)),
          ],
        ],
      );
}

class _MissionCard extends StatelessWidget {
  const _MissionCard({
    required this.session,
    required this.game,
    required this.team,
    required this.onOpen,
    required this.scanMode,
  });

  final Map<String, dynamic> session;
  final Map<String, dynamic>? game;
  final Map<String, dynamic> team;
  final VoidCallback onOpen;
  final bool scanMode;

  @override
  Widget build(BuildContext context) {
    final running = session['status'] == 'RUNNING';
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: (running ? NexoTheme.success : NexoTheme.amber)
                        .withValues(alpha: .14),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Icon(Icons.flag_outlined,
                      color: running ? NexoTheme.success : NexoTheme.amber),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(game?['name'] as String? ?? 'مأموریت NEXO',
                          style: const TextStyle(
                              fontWeight: FontWeight.w700, fontSize: 16)),
                      const SizedBox(height: 4),
                      Text(team['name'] as String? ?? 'تیم شما',
                          style: const TextStyle(color: NexoTheme.muted)),
                    ],
                  ),
                ),
                Icon(running ? Icons.play_circle_fill : Icons.pause_circle,
                    color: running ? NexoTheme.success : NexoTheme.amber),
              ],
            ),
            const SizedBox(height: 15),
            Text(
              running ? 'مأموریت در حال اجراست' : 'مأموریت موقتاً متوقف است',
              style: const TextStyle(color: NexoTheme.muted, fontSize: 13),
            ),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: onOpen,
              icon:
                  Icon(scanMode ? Icons.qr_code_scanner : Icons.arrow_forward),
              label: Text(scanMode ? 'ادامه و اسکن مرحله' : 'ادامه مأموریت'),
            ),
          ],
        ),
      ),
    );
  }
}

class _MapAccessCard extends StatelessWidget {
  const _MapAccessCard({required this.enabled, required this.onTap});

  final bool enabled;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Card(
        child: InkWell(
          borderRadius: BorderRadius.circular(NexoTheme.radius),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                const Icon(Icons.map_outlined, color: NexoTheme.cyan, size: 28),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('موقعیت مرحله',
                          style: TextStyle(fontWeight: FontWeight.w700)),
                      const SizedBox(height: 4),
                      Text(
                        enabled
                            ? 'موقعیت GPS از مرحله فعال ثبت و برای بررسی ارسال می‌شود.'
                            : 'موقعیت فقط در مرحله‌ای که GPS می‌خواهد ثبت می‌شود.',
                        style: const TextStyle(
                            color: NexoTheme.muted, fontSize: 12),
                      ),
                    ],
                  ),
                ),
                const Icon(Icons.chevron_left),
              ],
            ),
          ),
        ),
      );
}

class _CompletedMissionCard extends StatelessWidget {
  const _CompletedMissionCard({
    required this.session,
    required this.game,
    required this.team,
  });

  final Map<String, dynamic> session;
  final Map<String, dynamic>? game;
  final Map<String, dynamic> team;

  @override
  Widget build(BuildContext context) => Card(
        child: ListTile(
          leading:
              const Icon(Icons.emoji_events_outlined, color: NexoTheme.amber),
          title: Text(game?['name'] as String? ?? 'بازی NEXO'),
          subtitle: Text(team['name'] as String? ?? 'تیم شما'),
          trailing: const Icon(Icons.chevron_left),
          onTap: team['team_id'] is String
              ? () => Navigator.of(context).push(MaterialPageRoute<void>(
                    builder: (_) => GameResultPage(
                      sessionId: session['id'] as String,
                      teamId: team['team_id'] as String,
                      gameName: game?['name'] as String? ?? 'بازی NEXO',
                    ),
                  ))
              : null,
        ),
      );
}
