import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import '../rooms/room_pages.dart';
import '../rooms/room_repository.dart';
import '../teams/team_page.dart';
import '../teams/team_repository.dart';
import 'booking_repository.dart';

class GameDetailsPage extends StatefulWidget {
  const GameDetailsPage({required this.auth, required this.game, super.key});

  final AuthController auth;
  final Map<String, dynamic> game;

  @override
  State<GameDetailsPage> createState() => _GameDetailsPageState();
}

class _GameDetailsPageState extends State<GameDetailsPage> {
  late final BookingRepository bookings = BookingRepository(widget.auth.api);
  late final TeamRepository teams = TeamRepository(widget.auth.api);
  late Future<Map<String, dynamic>> game = _loadGame();
  late Future<List<NexoTeam>> teamList = teams.list();
  String? selectedTeamId;
  String? bookingKey;
  bool busy = false;

  Future<Map<String, dynamic>> _loadGame() =>
      bookings.game(widget.game['id'] as String);

  Future<void> _retry() async {
    setState(() {
      game = _loadGame();
      teamList = teams.list();
    });
  }

  Future<void> _openTeamManagement() async {
    await Navigator.of(context).push<void>(MaterialPageRoute<void>(
      builder: (_) => TeamPage(auth: widget.auth),
    ));
    if (mounted) {
      setState(() {
        teamList = teams.list();
      });
    }
  }

  Future<void> _book(Map<String, dynamic> game, NexoTeam team) async {
    if (busy) return;
    setState(() {
      busy = true;
      bookingKey ??= bookings.newIdempotencyKey();
    });
    try {
      final result = await bookings.create(
        gameId: game['id'] as String,
        teamId: team.id,
        roomName: team.name,
        idempotencyKey: bookingKey!,
      );
      bookingKey = null;
      final room = result['room'];
      if (room is Map<String, dynamic> && room['id'] is String && mounted) {
        await Navigator.of(context).push<void>(MaterialPageRoute<void>(
          builder: (_) => RoomDetailPage(
            auth: widget.auth,
            roomId: room['id'] as String,
            repository: RoomRepository(widget.auth.api),
          ),
        ));
      } else if (mounted) {
        final status = result['status'] as String? ?? '';
        final message = status == 'AWAITING_PAYMENT'
            ? 'درخواست رزرو ثبت شد؛ پرداخت خودکار شروع نشده است. وضعیت را در پرداخت‌های من ببین.'
            : 'درخواست رزرو ثبت شد.';
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(message)));
      }
    } catch (error) {
      if (mounted) {
        final message = error is ApiError
            ? error.userMessage
            : ApiClient.isNetworkFailure(error)
                ? 'اتصال برقرار نشد. پس از بررسی اینترنت دوباره تلاش کنید.'
                : 'ثبت رزرو انجام نشد. دوباره تلاش کنید.';
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(message)));
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('جزئیات بازی')),
        body: SafeArea(
          child: FutureBuilder<Map<String, dynamic>>(
            future: game,
            builder: (context, snapshot) {
              if (!snapshot.hasData &&
                  snapshot.connectionState != ConnectionState.done) {
                return const NexoLoading(
                    message: 'در حال دریافت مشخصات بازی...');
              }
              if (snapshot.hasError || !snapshot.hasData) {
                final failure =
                    snapshot.error ?? StateError('Game unavailable');
                return NexoErrorView(
                  message: failure is ApiError
                      ? failure.userMessage
                      : 'اطلاعات بازی دریافت نشد.',
                  offline: ApiClient.isNetworkFailure(failure),
                  onRetry: _retry,
                );
              }
              final value = snapshot.data!;
              final pricing =
                  value['pricing'] as Map<String, dynamic>? ?? const {};
              final mode = pricing['mode'] as String? ?? '';
              return ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  Card(
                      child: Padding(
                    padding: const EdgeInsets.all(20),
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(value['name'] as String? ?? 'بازی',
                              style: Theme.of(context).textTheme.headlineSmall),
                          if ((value['city'] as String? ?? '')
                              .trim()
                              .isNotEmpty) ...[
                            const SizedBox(height: 8),
                            Text(value['city'] as String),
                          ],
                          if ((value['description'] as String? ?? '')
                              .trim()
                              .isNotEmpty) ...[
                            const SizedBox(height: 16),
                            Text(value['description'] as String,
                                style: Theme.of(context).textTheme.bodyLarge),
                          ],
                          if (mode.isNotEmpty) ...[
                            const SizedBox(height: 16),
                            Text(_pricingLabel(mode, pricing),
                                style: Theme.of(context).textTheme.titleMedium),
                          ],
                        ]),
                  )),
                  const SizedBox(height: 12),
                  Text('انتخاب تیم',
                      style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 8),
                  FutureBuilder<List<NexoTeam>>(
                    future: teamList,
                    builder: (context, teamsSnapshot) {
                      if (!teamsSnapshot.hasData &&
                          teamsSnapshot.connectionState !=
                              ConnectionState.done) {
                        return const Card(
                            child: ListTile(
                                title: Text('در حال دریافت تیم‌ها...')));
                      }
                      if (teamsSnapshot.hasError) {
                        return NexoErrorView(
                            message: 'فهرست تیم‌ها دریافت نشد.',
                            offline: ApiClient.isNetworkFailure(
                                teamsSnapshot.error!),
                            onRetry: _retry);
                      }
                      final userId = widget.auth.user?['id'] as String? ?? '';
                      final mine = (teamsSnapshot.data ?? const <NexoTeam>[])
                          .where((team) => team.status == 'ACTIVE')
                          .toList(growable: false);
                      if (mine.isEmpty) {
                        return Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const NexoNotice(
                              'هنوز تیمی نساختی. ابتدا از بخش مدیریت تیم، تیم خودت رو تشکیل بده.',
                              icon: Icons.groups_2_outlined,
                            ),
                            const SizedBox(height: 12),
                            OutlinedButton.icon(
                              onPressed: _openTeamManagement,
                              icon: const Icon(Icons.groups_2_outlined),
                              label: const Text('رفتن به مدیریت تیم'),
                            ),
                          ],
                        );
                      }
                      final eligibleTeams = mine
                          .where((team) => team.eligibleForGame)
                          .toList(growable: false);
                      final ineligibleTeams = mine
                          .where((team) => !team.eligibleForGame)
                          .toList(growable: false);
                      if (eligibleTeams.isEmpty) {
                        return Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            const NexoNotice(
                              'هنوز تیمی با شرایط لازم برای این بازی نداری.',
                              icon: Icons.info_outline,
                            ),
                            const SizedBox(height: 12),
                            for (final team in ineligibleTeams)
                              _IneligibleTeamCard(team: team),
                            OutlinedButton.icon(
                              onPressed: _openTeamManagement,
                              icon: const Icon(Icons.groups_2_outlined),
                              label: const Text('مدیریت تیم‌ها'),
                            ),
                          ],
                        );
                      }
                      final selectedTeam = eligibleTeams
                          .where((team) => team.id == selectedTeamId)
                          .firstOrNull;
                      final pricingAvailable =
                          pricing['booking_available'] != false;
                      final gameActive =
                          (value['status'] as String? ?? 'ACTIVE') == 'ACTIVE';
                      final canBook = selectedTeam != null &&
                          selectedTeam.captainUserId == userId &&
                          selectedTeam.eligibleForGame &&
                          pricingAvailable &&
                          gameActive;
                      return Column(children: [
                        RadioGroup<String>(
                          groupValue: selectedTeamId,
                          onChanged: (value) =>
                              setState(() => selectedTeamId = value),
                          child: Column(children: [
                            for (final team in eligibleTeams)
                              Card(
                                child: RadioListTile<String>(
                                  value: team.id,
                                  title: Text(team.name),
                                  subtitle: Text(
                                      '${_faNumber(team.members.length)} از ۴ تا ۸ عضو · آماده بررسی رزرو'),
                                ),
                              ),
                          ]),
                        ),
                        for (final team in ineligibleTeams)
                          _IneligibleTeamCard(team: team),
                        if (selectedTeam != null)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 10),
                            child: Align(
                              alignment: AlignmentDirectional.centerStart,
                              child: Text(_teamBookingStatus(
                                selectedTeam,
                                userId,
                                gameActive: gameActive,
                                pricingAvailable: pricingAvailable,
                              )),
                            ),
                          ),
                        if (!pricingAvailable || !gameActive)
                          Padding(
                            padding: const EdgeInsets.only(bottom: 10),
                            child: Text(
                              !pricingAvailable
                                  ? 'رزرو این بازی در حال حاضر بسته است.'
                                  : 'این بازی در حال حاضر فعال نیست.',
                              textAlign: TextAlign.start,
                              style: TextStyle(
                                  color: Theme.of(context).colorScheme.error),
                            ),
                          ),
                        FilledButton.icon(
                          onPressed: busy || !canBook
                              ? null
                              : () {
                                  final team = selectedTeam;
                                  if (!team.eligibleForGame) {
                                    ScaffoldMessenger.of(context).showSnackBar(
                                        const SnackBar(
                                            content: Text(
                                                'برای رزرو، تیم باید ۴ تا ۸ عضو فعال داشته باشد.')));
                                    return;
                                  }
                                  _book(value, team);
                                },
                          icon: busy
                              ? const SizedBox.square(
                                  dimension: 18,
                                  child:
                                      CircularProgressIndicator(strokeWidth: 2))
                              : const Icon(Icons.event_available),
                          label: Text(
                              busy ? 'در حال ثبت رزرو...' : 'ادامه به رزرو'),
                        ),
                        const SizedBox(height: 8),
                        const Text(
                          'انتخاب تیم، به‌تنهایی جلسهٔ بازی یا پرداختی را آغاز نمی‌کند.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: NexoTheme.muted),
                        ),
                      ]);
                    },
                  ),
                ],
              );
            },
          ),
        ),
      );

  String _teamBookingStatus(
    NexoTeam team,
    String userId, {
    required bool gameActive,
    required bool pricingAvailable,
  }) {
    if (!gameActive) return 'این بازی فعال نیست.';
    if (!pricingAvailable) return 'رزرو این بازی بسته است.';
    if (team.captainUserId != userId) {
      return 'فقط کاپیتان تیم مجوز ثبت رزرو دارد.';
    }
    if (team.members.length < 4) {
      return '${_faNumber(team.members.length)} از ۴ عضو؛ ${_faNumber(4 - team.members.length)} عضو دیگر لازم است.';
    }
    if (team.members.length > 8) {
      return 'ظرفیت تیم بیش از حد مجاز است.';
    }
    return 'تیم آماده رزرو است.';
  }

  String _pricingLabel(String mode, Map<String, dynamic> pricing) {
    final currency = pricing['currency'] as String? ?? '';
    final amount = (pricing['amount'] as num?)?.toInt() ?? 0;
    if (mode == 'FREE') return 'رایگان';
    if (mode == 'PER_PLAYER') return 'پرداخت هر بازیکن: $amount $currency';
    return 'پرداخت یک‌جای تیم: $amount $currency';
  }
}

class _IneligibleTeamCard extends StatelessWidget {
  const _IneligibleTeamCard({required this.team});

  final NexoTeam team;

  @override
  Widget build(BuildContext context) {
    final count = team.members.length;
    final reason = count < 4
        ? '${_faNumber(count)} عضو فعال؛ ${_faNumber(4 - count)} عضو دیگر لازم است.'
        : count > 8
            ? 'تعداد اعضای فعال از سقف ۸ نفر بیشتر است.'
            : 'این تیم طبق وضعیت دریافتی از سرور برای رزرو آماده نیست.';
    return Card(
      child: ListTile(
        leading: const Icon(Icons.groups_outlined),
        title: Text(team.name),
        subtitle: Text(reason),
      ),
    );
  }
}

String _faNumber(int value) => value.toString().replaceAllMapped(
      RegExp(r'[0-9]'),
      (match) => '۰۱۲۳۴۵۶۷۸۹'[int.parse(match[0]!)],
    );
