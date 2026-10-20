import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/app/nexo_theme.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/errors/api_error.dart';
import 'package:nexo_player/core/offline/local_database.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/teams/lobby_page.dart';
import 'package:nexo_player/features/teams/join_team_page.dart';
import 'package:nexo_player/features/teams/team_page.dart';
import 'package:nexo_player/features/teams/team_repository.dart';

const userId = 'f6f54af0-427a-4bcb-b45d-c7dd59f04fc9';
const teamId = 'a4b494b6-b569-47db-8198-12e30120fa75';
const sessionId = '1dc94618-e56d-4402-91d8-0f6a1853c650';

Map<String, dynamic> teamJson({int members = 4}) => {
      'id': teamId,
      'name': 'کاوشگران',
      'status': 'ACTIVE',
      'captain_user_id': userId,
      'captain': {'id': userId, 'name': 'آرمان'},
      'members': [
        for (var i = 0; i < members; i++)
          {
            'user_id': i == 0 ? userId : 'member-$i',
            'name': i == 0 ? 'آرمان' : 'بازیکن $i',
            'status': 'ACTIVE',
          }
      ],
      'member_count': members,
      'eligible_for_game': members >= 4 && members <= 8,
    };

Map<String, dynamic> sessionJson(
        {String status = 'REGISTRATION', List<dynamic> teams = const []}) =>
    {
      'id': sessionId,
      'game_id': 'game-public-id',
      'organization_id': 'organization-public-id',
      'status': status,
      'teams': teams,
    };

http.Response response(Object data, [int status = 200]) => http.Response(
      jsonEncode({'data': data}),
      status,
      headers: const {'content-type': 'application/json'},
    );

class _MemoryStore extends SecureStore {
  final values = <String, String>{
    'nexo.device_id': '9b93408e-778f-4619-b4f5-c01ea395b895'
  };
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String value) async => values[key] = value;
  @override
  Future<void> delete(String key) async => values.remove(key);
}

class _NoopDatabase extends LocalDatabase {
  @override
  Future<void> clearCache() async {}
}

AuthController auth(ApiClient api) => AuthController(
      api,
      database: _NoopDatabase(),
      identity: OfflineIdentity(),
    )..user = {'id': userId, 'name': 'آرمان'};

Widget host(Widget child) => MaterialApp(
      theme: NexoTheme.data,
      locale: const Locale('fa'),
      home: Builder(
        builder: (context) => Directionality(
          textDirection: TextDirection.rtl,
          child: MediaQuery(
            data: MediaQuery.of(context)
                .copyWith(textScaler: const TextScaler.linear(1.25)),
            child: child,
          ),
        ),
      ),
    );

void main() {
  testWidgets('invite code is previewed before a captain approval request is sent', (tester) async {
    final code = 'a' * 64;
    var requestSent = 0;
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path == '/api/v1/team-invites/$code') {
          return response({
            'team': {'name': 'تیم آذرخش', 'status': 'ACTIVE', 'captain': {'name': 'آرمان'}},
            'member_count': 3,
            'capacity': 8,
            'expires_at': '2026-10-28T00:00:00Z',
          });
        }
        if (request.url.path == '/api/v1/teams/join') {
          requestSent++;
          expect(jsonDecode(request.body), {'invite_code': code});
          expect(request.headers['Idempotency-Key'], isNotEmpty);
          return response({'id': 'request-id', 'status': 'PENDING', 'team_name': 'تیم آذرخش'});
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = auth(api);
    await tester.pumpWidget(host(JoinTeamPage(auth: controller, initialInviteCode: code)));
    await tester.pumpAndSettle();
    expect(find.text('تیم آذرخش'), findsOneWidget);
    expect(find.text('درخواست پیوستن'), findsOneWidget);
    expect(requestSent, 0);
    await tester.ensureVisible(find.text('درخواست پیوستن'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('درخواست پیوستن'));
    await tester.pumpAndSettle();
    expect(requestSent, 1);
    expect(extractInviteCode('nexo://invite/$code'), code);
    expect(extractInviteCode('/invite/$code'), code);
    expect(extractInviteCode('nexo://invite/not-a-token'), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    controller.dispose();
  });

  testWidgets('team lobby selects one real team and renders eight player slots',
      (tester) async {
    final second = teamJson(members: 3)
      ..['id'] = 'team-second'
      ..['name'] = 'بازیکنان دوم';
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path == '/api/v1/teams' && request.method == 'GET') {
          return response([teamJson(members: 2), second]);
        }
        if (request.url.path.endsWith('/invites') || request.url.path.endsWith('/join-requests')) return response([]);
        if (request.url.path == '/api/v1/me/team-invitations') {
          return response([]);
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = auth(api);

    await tester.pumpWidget(host(TeamPage(auth: controller)));
    await tester.pumpAndSettle();
    expect(find.byType(DropdownButtonFormField<NexoTeam>), findsOneWidget);
    expect(find.text('دعوت به تیم'), findsOneWidget);
    await tester.tap(find.byType(DropdownButtonFormField<NexoTeam>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('بازیکنان دوم').last);
    await tester.pumpAndSettle();
    expect(find.text('بازیکنان دوم'), findsWidgets);
    for (var attempt = 0;
        attempt < 6 && find.text('ترکیب تیم').evaluate().isEmpty;
        attempt++) {
      await tester.drag(find.byType(ListView), const Offset(0, -320));
      await tester.pumpAndSettle();
    }
    await tester.ensureVisible(find.text('ترکیب تیم'));
    await tester.pumpAndSettle();
    expect(find.text('جای خالی'), findsNWidgets(5));
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    controller.dispose();
  });

  testWidgets('user without a team can open received invitations from lobby',
      (tester) async {
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path == '/api/v1/teams') return response([]);
        if (request.url.path == '/api/v1/me/team-invitations') {
          return response([]);
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = auth(api);
    await tester.pumpWidget(host(TeamPage(auth: controller)));
    await tester.pumpAndSettle();

    expect(find.text('ساخت تیم'), findsOneWidget);
    await tester.tap(find.byTooltip('دعوت‌های دریافتی'));
    await tester.pumpAndSettle();
    expect(find.text('دعوت‌های تیم'), findsOneWidget);
    expect(find.text('دعوت تازه‌ای نداری'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    controller.dispose();
  });

  testWidgets(
      'team creation widget posts the contract and refreshes membership',
      (tester) async {
    tester.view.physicalSize = const Size(320, 720);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    var createCalls = 0;
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path.endsWith('/teams') && request.method == 'GET') {
          return response(createCalls == 0 ? [] : [teamJson()]);
        }
        if (request.url.path.endsWith('/teams/$teamId/invites') || request.url.path.endsWith('/teams/$teamId/join-requests')) {
          return response([]);
        }
        if (request.url.path.endsWith('/teams/$teamId/invites') &&
            request.method == 'GET') {
          return response([]);
        }
        if (request.url.path.endsWith('/teams') && request.method == 'POST') {
          createCalls++;
          expect(jsonDecode(request.body), {'name': 'کاوشگران'});
          expect(request.headers['Idempotency-Key'], isNotEmpty);
          return response(teamJson(), 201);
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = auth(api);
    await tester.pumpWidget(host(TeamPage(auth: controller)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ساخت تیم'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'کاوشگران');
    await tester.tap(find.text('ادامه'));
    await tester.pumpAndSettle();
    expect(createCalls, 1);
    expect(find.text('کاوشگران'), findsWidgets);
    expect(find.textContaining('کاپیتان:'), findsOneWidget);
    expect(find.text('۴ از ۸ عضو'), findsOneWidget);
    expect(find.text('آماده ثبت‌نام در بازی'), findsOneWidget);
    await tester.drag(find.byType(ListView), const Offset(0, -1200));
    await tester.pumpAndSettle();
    expect(find.text('اعضای تیم'), findsNothing);
    expect(find.text('ترکیب تیم'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    controller.dispose();
  });

  test('team repository join and invite use only real API requests', () async {
    final paths = <String>[];
    final repository = TeamRepository(ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        paths.add(request.url.path);
        if (request.url.path.endsWith('/teams/join')) {
          expect(jsonDecode(request.body), {'invite_code': 'one-time-code'});
          expect(request.headers['Idempotency-Key'], isNotEmpty);
          return response({'id': 'join-request-id', 'status': 'PENDING', 'team_name': 'کاوشگران'});
        }
        expect(request.url.path, '/api/v1/teams/$teamId/invites');
        expect(request.method, 'POST');
        return response(
            {'id': 'invite-id', 'invite_code': 'secret-returned-once'}, 201);
      }),
    ));

    expect((await repository.join(' one-time-code ')).id, 'join-request-id');
    expect((await repository.createInvite(teamId))['invite_code'],
        'secret-returned-once');
    expect(paths, ['/api/v1/teams/join', '/api/v1/teams/$teamId/invites']);
  });

  testWidgets(
      'regular member can leave only after confirming and sees no captain actions',
      (tester) async {
    var leaveCalls = 0;
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path.endsWith('/teams') && request.method == 'GET') {
          return response(leaveCalls == 0 ? [teamJson()] : []);
        }
        if (request.url.path.endsWith('/teams/$teamId/invites') &&
            request.method == 'GET') {
          return response([]);
        }
        if (request.url.path.endsWith('/teams/$teamId/leave')) {
          leaveCalls++;
          return http.Response('', 204);
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = auth(api)..user = {'id': 'member-1', 'name': 'عضو'};
    await tester.pumpWidget(host(TeamPage(auth: controller)));
    await tester.pumpAndSettle();

    await tester.tap(find.byTooltip('عملیات تیم'));
    await tester.pumpAndSettle();
    expect(find.text('خروج از تیم'), findsOneWidget);
    expect(find.text('دریافت کد دعوت'), findsNothing);
    expect(find.text('انتقال کاپیتانی'), findsNothing);
    await tester.tap(find.text('خروج از تیم'));
    await tester.pumpAndSettle();
    expect(leaveCalls, 0);
    await tester.tap(find.text('انصراف'));
    await tester.pumpAndSettle();
    expect(leaveCalls, 0);

    await tester.tap(find.byTooltip('عملیات تیم'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('خروج از تیم'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'خروج از تیم'));
    await tester.pumpAndSettle();
    expect(leaveCalls, 1);
    expect(find.text('هنوز عضو تیمی نیستید'), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
    controller.dispose();
  });

  test(
      'lobby retries registration with same idempotency key and marks readiness on bound device',
      () async {
    final keys = <String>[];
    final requests = <String>[];
    final repository = LobbyRepository(ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        requests.add(request.url.path);
        if (request.url.path.endsWith('/register-team')) {
          keys.add(request.headers['Idempotency-Key']!);
          expect(jsonDecode(request.body), {'team_id': teamId});
          return response(sessionJson(teams: [
            {
              'team_id': teamId,
              'name': 'کاوشگران',
              'status': 'REGISTERED',
              'players': []
            }
          ]));
        }
        if (request.url.path.endsWith('/bind-device')) {
          expect(jsonDecode(request.body)['device_id'],
              '9b93408e-778f-4619-b4f5-c01ea395b895');
          return response(sessionJson());
        }
        if (request.url.path.endsWith('/ready')) {
          expect(request.headers['X-Device-Id'],
              '9b93408e-778f-4619-b4f5-c01ea395b895');
          expect(jsonDecode(request.body), {'ready': true});
          return response(sessionJson());
        }
        throw StateError('Unexpected ${request.url.path}');
      }),
    ));

    await repository.registerTeam(sessionId, teamId, 'same-registration-key');
    await repository.registerTeam(sessionId, teamId, 'same-registration-key');
    await repository.ready(sessionId, true);
    expect(keys, ['same-registration-key', 'same-registration-key']);
    expect(requests, [
      '/api/v1/game-sessions/$sessionId/register-team',
      '/api/v1/game-sessions/$sessionId/register-team',
      '/api/v1/game-sessions/$sessionId/bind-device',
      '/api/v1/game-sessions/$sessionId/ready',
    ]);
  });

  testWidgets('lobby shows registration state and never offers player start',
      (tester) async {
    tester.view.physicalSize = const Size(320, 720);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final registrationKeys = <String>[];
    var registered = false;
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path == '/api/v1/game-sessions') {
          final roster = registered
              ? [
                  {
                    'team_id': teamId,
                    'name': 'کاوشگران',
                    'status': 'REGISTERED',
                    'player_count': 1,
                    'players': [
                      {
                        'user': {'id': userId, 'name': 'آرمان'},
                        'status': 'JOINED'
                      }
                    ],
                  }
                ]
              : <dynamic>[];
          return response([
            sessionJson(teams: roster),
            sessionJson(status: 'FINISHED'),
          ]);
        }
        if (request.url.path == '/api/v1/teams') return response([teamJson()]);
        if (request.url.path.endsWith('/register-team')) {
          registrationKeys.add(request.headers['Idempotency-Key']!);
          if (registrationKeys.length == 1) {
            return http.Response(
              jsonEncode({
                'error': {'code': 'NETWORK_ERROR', 'message': 'offline'}
              }),
              503,
            );
          }
          registered = true;
          return response(sessionJson(teams: [
            {
              'team_id': teamId,
              'name': 'کاوشگران',
              'status': 'REGISTERED',
              'player_count': 1,
              'players': [
                {
                  'user': {'id': userId, 'name': 'آرمان'},
                  'status': 'JOINED'
                }
              ],
            }
          ]));
        }
        throw StateError('Unexpected ${request.url.path}');
      }),
    );
    final controller = auth(api);
    await tester.pumpWidget(host(LobbyPage(auth: controller)));
    await tester.pumpAndSettle();
    expect(find.text('ثبت‌نام'), findsOneWidget);
    expect(find.text('پایان‌یافته'), findsOneWidget);
    expect(find.text('ثبت‌نام کاوشگران در نشست'), findsOneWidget);
    expect(find.text('شروع بازی'), findsNothing);
    await tester.tap(find.text('ثبت‌نام کاوشگران در نشست'));
    await tester.pumpAndSettle();
    expect(registrationKeys, hasLength(1));
    await tester.pump(const Duration(seconds: 5));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('ثبت‌نام کاوشگران در نشست'));
    await tester.tap(find.text('ثبت‌نام کاوشگران در نشست'));
    await tester.pumpAndSettle();
    expect(registrationKeys, hasLength(2));
    expect(registrationKeys[0], registrationKeys[1]);
    expect(find.text('اعلام آمادگی با این دستگاه'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
    controller.dispose();
  });

  test(
      'team and lobby errors explain capacity, expiry, eligibility, and captain permissions',
      () {
    expect(teamError(const ApiError(code: 'TEAM_FULL', message: '')),
        contains('۸ عضو'));
    expect(teamError(const ApiError(code: 'INVITE_EXPIRED', message: '')),
        contains('منقضی'));
    expect(lobbyError(const ApiError(code: 'TEAM_NOT_ELIGIBLE', message: '')),
        contains('۴ تا ۸'));
    expect(
        lobbyError(const ApiError(code: 'AUTHORIZATION_DENIED', message: '')),
        contains('کاپیتان'));
  });
}
