import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import '../runtime/gameplay_page.dart';
import '../teams/team_repository.dart';

class GameEntryPage extends StatefulWidget {
  const GameEntryPage({required this.auth, required this.game, super.key});

  final AuthController auth;
  final Map<String, dynamic> game;

  @override
  State<GameEntryPage> createState() => _GameEntryPageState();
}

class _GameEntryPageState extends State<GameEntryPage>
    with WidgetsBindingObserver {
  late final LobbyRepository lobby = LobbyRepository(widget.auth.api);
  late final TeamRepository teamsRepository = TeamRepository(widget.auth.api);
  late Future<_EntryData> content = _load();
  bool busy = false;
  bool openingGameplay = false;
  String? selectedTeamId;
  String? selectedSessionId;
  final Map<String, String> registrationKeys = {};

  String get gameId => widget.game['id'] as String;
  String get gameName => widget.game['name'] as String? ?? 'بازی';
  String get userId => widget.auth.user?['id'] as String? ?? '';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      refresh();
    }
  }

  Future<_EntryData> _load() async {
    final values =
        await Future.wait([lobby.sessions(), teamsRepository.list()]);
    final sessions = (values[0] as List<LobbySession>)
        .where((session) => session.gameId == gameId)
        .toList(growable: false);
    return _EntryData(sessions, values[1] as List<NexoTeam>);
  }

  Future<void> refresh() async {
    if (!mounted || busy) return;
    setState(() => content = _load());
    try {
      await content;
    } catch (_) {
      // The error state contains the retry action.
    }
  }

  Future<void> register(LobbySession session, NexoTeam team) async {
    if (busy) return;
    final key = '${session.id}:${team.id}';
    final idempotencyKey =
        registrationKeys.putIfAbsent(key, () => const Uuid().v4());
    setState(() => busy = true);
    try {
      await lobby.registerTeam(session.id, team.id, idempotencyKey);
      registrationKeys.remove(key);
      await _reloadAfterAction();
    } catch (error) {
      _showError(error);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> markReady(LobbySession session) async {
    if (busy) return;
    setState(() => busy = true);
    try {
      await lobby.ready(session.id, true);
      await _reloadAfterAction();
    } catch (error) {
      _showError(error);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _reloadAfterAction() async {
    if (!mounted) return;
    setState(() => content = _load());
    await content;
  }

  void _showError(Object error) {
    if (!mounted) return;
    final message = error is ApiError
        ? _friendlyError(error)
        : 'اتصال برقرار نشد. اینترنت را بررسی کنید و دوباره تلاش کنید.';
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(message)));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(gameName)),
        body: SafeArea(
          child: FutureBuilder<_EntryData>(
            future: content,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done &&
                  !snapshot.hasData) {
                return const NexoLoading(message: 'دارم بازی را آماده می‌کنم…');
              }
              if (snapshot.hasError && !snapshot.hasData) {
                return NexoErrorView(
                  message: ApiClient.isNetworkFailure(snapshot.error!)
                      ? 'برای دیدن بازی به اینترنت وصل شوید.'
                      : 'اطلاعات بازی دریافت نشد. دوباره تلاش کنید.',
                  offline: ApiClient.isNetworkFailure(snapshot.error!),
                  onRetry: refresh,
                );
              }
              final data = snapshot.data!;
              final validSessions = _validSessions(data.sessions);
              final session = _chooseSession(validSessions);
              return RefreshIndicator(
                onRefresh: refresh,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(NexoSpace.x4),
                  children: [
                    Text('آماده‌ای؟',
                        style: Theme.of(context).textTheme.headlineSmall),
                    const SizedBox(height: NexoSpace.x2),
                    if (session == null && validSessions.length > 1)
                      _sessionChoices(validSessions)
                    else if (session == null)
                      const NexoEmptyView(
                        title: 'هنوز نوبت این بازی نرسیده',
                        message:
                            'وقتی برگزارکننده بازی را باز کند، همین‌جا می‌بینی.',
                        icon: Icons.hourglass_empty,
                      )
                    else
                      _sessionContent(session, data.teams),
                  ],
                ),
              );
            },
          ),
        ),
      );

  LobbySession? _chooseSession(List<LobbySession> sessions) {
    if (selectedSessionId != null) {
      return sessions
          .where((session) => session.id == selectedSessionId)
          .firstOrNull;
    }
    final registered = sessions
        .where((session) => _ownSessionTeam(session, userId) != null)
        .toList(growable: false);
    if (registered.length == 1) return registered.first;
    if (sessions.length == 1) return sessions.first;
    return null;
  }

  List<LobbySession> _validSessions(List<LobbySession> sessions) => sessions
      .where((session) => ['REGISTRATION', 'READY', 'RUNNING', 'PAUSED']
          .contains(session.status))
      .toList(growable: false);

  Widget _sessionChoices(List<LobbySession> sessions) {
    final distinguishable = sessions.every(_hasHumanMarker);
    if (!distinguishable) {
      return const _AmbiguousSessionsCard();
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text('کدوم بازی؟',
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
        const SizedBox(height: NexoSpace.x2),
        const Text('یک نوبت را انتخاب کن.'),
        const SizedBox(height: NexoSpace.x3),
        for (final session in sessions)
          Card(
            child: ListTile(
              leading: const Icon(Icons.sports_esports_outlined,
                  color: NexoTheme.amber),
              title: Text(_sessionStatusLabel(session.status)),
              subtitle: Text(_sessionMarker(session)),
              trailing: const Icon(Icons.chevron_left),
              onTap: () => setState(() => selectedSessionId = session.id),
            ),
          ),
      ],
    );
  }

  Widget _sessionContent(LobbySession session, List<NexoTeam> teams) {
    final registered = _ownSessionTeam(session, userId);
    final player =
        registered?.players.where((item) => item.userId == userId).firstOrNull;
    if (session.status == 'RUNNING' && registered != null) {
      if (!openingGameplay) {
        openingGameplay = true;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) {
            Navigator.of(context).pushReplacement(MaterialPageRoute<void>(
              builder: (_) => GameplayPage(
                sessionId: session.id,
                gameName: gameName,
                teamName: registered.name,
              ),
            ));
          }
        });
      }
      return const NexoLoading(message: 'بازی شروع شد…');
    }
    if (registered != null) {
      if (player?.status == 'READY') {
        return const _WaitingCard();
      }
      if (session.status == 'REGISTRATION' || session.status == 'READY') {
        return Column(
          children: [
            const _StepCard(
              icon: Icons.groups_outlined,
              title: 'تیمت وارد شد',
              message: 'حالا فقط خودت آماده شو.',
            ),
            const SizedBox(height: NexoSpace.x3),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: busy ? null : () => markReady(session),
                child: Text(busy ? 'یک لحظه…' : 'آماده‌ام!'),
              ),
            ),
          ],
        );
      }
      return const _WaitingCard();
    }
    final myTeams = teams
        .where((team) => team.members.any((member) => member.userId == userId))
        .toList(growable: false);
    final captainTeams = myTeams
        .where((team) => team.captainUserId == userId && team.eligibleForGame)
        .where((team) => !session.teams.any((entry) =>
            entry.teamId == team.id &&
            !['REMOVED', 'DISQUALIFIED'].contains(entry.status)))
        .toList(growable: false);
    if (session.status != 'REGISTRATION') {
      return const _StepCard(
        icon: Icons.hourglass_empty,
        title: 'ثبت تیم بسته است',
        message: 'فعلاً منتظر بمان؛ برگزارکننده وضعیت بازی را تغییر می‌دهد.',
      );
    }
    if (captainTeams.isEmpty) {
      return const _StepCard(
        icon: Icons.groups_outlined,
        title: 'هنوز با تیم وارد نشده‌ای',
        message: 'از کاپیتان تیمت بخواه شما را وارد این بازی کند.',
      );
    }
    selectedTeamId ??= captainTeams.first.id;
    final selectedTeam =
        captainTeams.where((team) => team.id == selectedTeamId).firstOrNull;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const _StepCard(
          icon: Icons.groups_outlined,
          title: 'تیمت را انتخاب کن',
          message: 'بعد از ثبت، اعضای تیم می‌توانند آماده شوند.',
        ),
        const SizedBox(height: NexoSpace.x2),
        ...captainTeams.map((team) => Card(
              margin: const EdgeInsets.only(bottom: NexoSpace.x2),
              child: ListTile(
                leading: Icon(
                  team.id == selectedTeamId
                      ? Icons.radio_button_checked
                      : Icons.radio_button_unchecked,
                  color: NexoTheme.amber,
                ),
                title: Text(team.name),
                subtitle: Text('${team.members.length} نفر'),
                onTap: busy
                    ? null
                    : () => setState(() => selectedTeamId = team.id),
              ),
            )),
        const SizedBox(height: NexoSpace.x2),
        FilledButton(
          onPressed: busy || selectedTeam == null
              ? null
              : () => register(session, selectedTeam),
          child: Text(busy ? 'دارم ثبت می‌کنم…' : 'ورود با این تیم'),
        ),
      ],
    );
  }
}

class _EntryData {
  const _EntryData(this.sessions, this.teams);

  final List<LobbySession> sessions;
  final List<NexoTeam> teams;
}

class _StepCard extends StatelessWidget {
  const _StepCard(
      {required this.icon, required this.title, required this.message});

  final IconData icon;
  final String title;
  final String message;

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(NexoSpace.x6),
          child: Column(
            children: [
              Icon(icon, size: 48, color: NexoTheme.amber),
              const SizedBox(height: NexoSpace.x3),
              Text(title,
                  style: Theme.of(context).textTheme.titleLarge,
                  textAlign: TextAlign.center),
              const SizedBox(height: NexoSpace.x2),
              Text(message, textAlign: TextAlign.center),
            ],
          ),
        ),
      );
}

class _WaitingCard extends StatelessWidget {
  const _WaitingCard();

  @override
  Widget build(BuildContext context) => const _StepCard(
        icon: Icons.hourglass_top,
        title: 'عالیه! منتظر شروع بازی باش',
        message: 'وقتی برگزارکننده شروع کند، بازی خودش باز می‌شود.',
      );
}

class _AmbiguousSessionsCard extends StatelessWidget {
  const _AmbiguousSessionsCard();

  @override
  Widget build(BuildContext context) => const _StepCard(
        icon: Icons.help_outline,
        title: 'کدوم بازی؟',
        message:
            'چند نوبت از این بازی پیدا شد، اما زمان آن‌ها از سرور نیامده است. از برگزارکننده زمان بازی را بپرس.',
      );
}

bool _hasHumanMarker(LobbySession session) =>
    session.scheduledStartAt != null || session.registrationClosesAt != null;

String _sessionStatusLabel(String status) => switch (status) {
      'REGISTRATION' => 'ثبت‌نام باز است',
      'READY' => 'منتظر شروع',
      'RUNNING' => 'بازی در جریان است',
      'PAUSED' => 'بازی موقتاً متوقف است',
      _ => 'نوبت بازی',
    };

String _sessionMarker(LobbySession session) {
  final start = _formatDate(session.scheduledStartAt);
  if (start != null) return 'شروع بازی: $start';
  final close = _formatDate(session.registrationClosesAt);
  if (close != null) return 'پایان ثبت‌نام: $close';
  return 'نوبت بازی';
}

String? _formatDate(String? value) {
  final parsed = value == null ? null : DateTime.tryParse(value)?.toLocal();
  if (parsed == null) return null;
  final hour = parsed.hour.toString().padLeft(2, '0');
  final minute = parsed.minute.toString().padLeft(2, '0');
  return '${parsed.year}/${parsed.month.toString().padLeft(2, '0')}/${parsed.day.toString().padLeft(2, '0')}، $hour:$minute';
}

SessionTeam? _ownSessionTeam(LobbySession session, String userId) =>
    session.teams
        .where((team) => team.players.any((player) =>
            player.userId == userId &&
            !['REMOVED', 'DISQUALIFIED'].contains(player.status)))
        .firstOrNull;

String _friendlyError(ApiError error) => switch (error.code) {
      'AUTHORIZATION_DENIED' ||
      'AUTHORIZATION_REQUIRED' =>
        'فقط کاپیتان می‌تواند تیم را وارد بازی کند.',
      'SESSION_NOT_OPEN' ||
      'STATE_TRANSITION_INVALID' =>
        'ثبت تیم بسته شده است. کمی بعد دوباره نگاه کن.',
      'TEAM_NOT_ELIGIBLE' => 'تیم باید حداقل ۴ عضو فعال داشته باشد.',
      'DEVICE_NOT_BOUND' => 'آماده‌شدن انجام نشد. دوباره روی «آماده‌ام!» بزن.',
      'SESSION_NOT_READY' => 'هنوز همه اعضای تیم آماده نیستند.',
      _ => error.userMessage,
    };
