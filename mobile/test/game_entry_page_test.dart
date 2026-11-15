import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/offline/local_database.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/player/game_entry_page.dart';

const _userId = 'user-1';
const _teamId = 'team-1';
const _sessionId = 'session-1';

class _Store extends SecureStore {
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

class _Database extends LocalDatabase {
  @override
  Future<void> clearCache() async {}
}

http.Response _response(Object value, [int status = 200]) => http.Response(
      jsonEncode({'data': value}),
      status,
      headers: const {'content-type': 'application/json'},
    );

Map<String, dynamic> _team({String captain = _userId}) => {
      'id': _teamId,
      'name': 'تیم آبی',
      'status': 'ACTIVE',
      'captain_user_id': captain,
      'captain': {'id': captain, 'name': 'کاپیتان'},
      'members': [
        for (var index = 0; index < 4; index++)
          {
            'user_id': index == 0 ? _userId : 'member-$index',
            'status': 'ACTIVE'
          },
      ],
      'eligible_for_game': true,
    };

Map<String, dynamic> _session({
  String status = 'REGISTRATION',
  List<dynamic> teams = const [],
  String? scheduledStartAt,
}) =>
    {
      'id': _sessionId,
      'game_id': 'game-1',
      'status': status,
      'scheduled_start_at': scheduledStartAt,
      'teams': teams,
    };

AuthController _auth(ApiClient api, {String id = _userId}) => AuthController(
      api,
      database: _Database(),
      identity: OfflineIdentity(),
    )..user = {'id': id, 'name': 'بازیکن'};

Widget _host(Widget child) => MaterialApp(
      home: Directionality(textDirection: TextDirection.rtl, child: child),
    );

void main() {
  testWidgets('captain sees one simple team entry action', (tester) async {
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path.endsWith('/game-sessions')) {
          return _response([_session()]);
        }
        if (request.url.path.endsWith('/teams')) return _response([_team()]);
        throw StateError('Unexpected ${request.url.path}');
      }),
    );
    final auth = _auth(api);
    await tester.pumpWidget(_host(GameEntryPage(
      auth: auth,
      game: const {'id': 'game-1', 'name': 'شهر بازی'},
    )));
    await tester.pumpAndSettle();

    expect(find.text('تیمت را انتخاب کن'), findsOneWidget);
    expect(find.text('ورود با این تیم'), findsOneWidget);
    expect(find.textContaining('ثبت‌نام'), findsNothing);
    auth.dispose();
  });

  testWidgets('ordinary player is told to wait for the captain',
      (tester) async {
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path.endsWith('/game-sessions')) {
          return _response([_session()]);
        }
        if (request.url.path.endsWith('/teams')) {
          return _response([_team(captain: 'captain-2')]);
        }
        throw StateError('Unexpected ${request.url.path}');
      }),
    );
    final auth = _auth(api);
    await tester.pumpWidget(_host(GameEntryPage(
      auth: auth,
      game: const {'id': 'game-1', 'name': 'شهر بازی'},
    )));
    await tester.pumpAndSettle();

    expect(find.text('از کاپیتان تیمت بخواه شما را وارد این بازی کند.'),
        findsOneWidget);
    expect(find.text('ورود با این تیم'), findsNothing);
    auth.dispose();
  });

  testWidgets('registered player gets only the readiness action',
      (tester) async {
    final requests = <String>[];
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        requests.add(request.url.path);
        if (request.url.path.endsWith('/game-sessions')) {
          return _response([
            _session(teams: [
              {
                'team_id': _teamId,
                'name': 'تیم آبی',
                'status': 'REGISTERED',
                'players': [
                  {
                    'user': {'id': _userId, 'name': 'بازیکن'},
                    'status': 'JOINED'
                  },
                ],
              }
            ])
          ]);
        }
        if (request.url.path.endsWith('/teams')) return _response([_team()]);
        if (request.url.path.endsWith('/bind-device')) return _response({});
        if (request.url.path.endsWith('/ready')) {
          return _response(_session(status: 'READY', teams: [
            {
              'team_id': _teamId,
              'name': 'تیم آبی',
              'status': 'REGISTERED',
              'players': [
                {
                  'user': {'id': _userId},
                  'status': 'READY'
                },
              ],
            }
          ]));
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final auth = _auth(api);
    await tester.pumpWidget(_host(GameEntryPage(
      auth: auth,
      game: const {'id': 'game-1', 'name': 'شهر بازی'},
    )));
    await tester.pumpAndSettle();
    expect(find.text('آماده‌ام!'), findsOneWidget);
    expect(find.text('ورود با این تیم'), findsNothing);
    await tester.tap(find.text('آماده‌ام!'));
    await tester.pumpAndSettle();
    await tester.pump(const Duration(milliseconds: 100));
    expect(requests, contains('/api/v1/game-sessions/$_sessionId/bind-device'));
    expect(requests, contains('/api/v1/game-sessions/$_sessionId/ready'));
    auth.dispose();
  });

  testWidgets('ready player sees waiting message and no repeated button',
      (tester) async {
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path.endsWith('/game-sessions')) {
          return _response([
            _session(teams: [
              {
                'team_id': _teamId,
                'name': 'تیم آبی',
                'status': 'REGISTERED',
                'players': [
                  {
                    'user': {'id': _userId},
                    'status': 'READY'
                  },
                ],
              }
            ])
          ]);
        }
        if (request.url.path.endsWith('/teams')) return _response([_team()]);
        throw StateError('Unexpected ${request.url.path}');
      }),
    );
    final auth = _auth(api);
    await tester.pumpWidget(_host(GameEntryPage(
      auth: auth,
      game: const {'id': 'game-1', 'name': 'شهر بازی'},
    )));
    await tester.pumpAndSettle();
    expect(find.text('عالیه! منتظر شروع بازی باش'), findsOneWidget);
    expect(find.text('آماده‌ام!'), findsNothing);
    auth.dispose();
  });

  testWidgets('multiple distinguishable sessions ask which one to open',
      (tester) async {
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path.endsWith('/game-sessions')) {
          return _response([
            _session(scheduledStartAt: '2030-01-01T10:00:00Z'),
            {
              ..._session(),
              'id': 'session-2',
              'scheduled_start_at': '2030-01-01T12:00:00Z'
            },
          ]);
        }
        if (request.url.path.endsWith('/teams')) return _response([_team()]);
        throw StateError('Unexpected ${request.url.path}');
      }),
    );
    final auth = _auth(api);
    await tester.pumpWidget(_host(GameEntryPage(
      auth: auth,
      game: const {'id': 'game-1', 'name': 'شهر بازی'},
    )));
    await tester.pumpAndSettle();

    expect(find.text('کدوم بازی؟'), findsOneWidget);
    expect(find.text('ورود با این تیم'), findsNothing);
    await tester.tap(find.textContaining('شروع بازی:').first);
    await tester.pumpAndSettle();
    expect(find.text('ورود با این تیم'), findsOneWidget);
    auth.dispose();
  });

  testWidgets('multiple sessions without a real time marker stay ambiguous',
      (tester) async {
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path.endsWith('/game-sessions')) {
          return _response([
            _session(),
            {..._session(), 'id': 'session-2'},
          ]);
        }
        if (request.url.path.endsWith('/teams')) return _response([_team()]);
        throw StateError('Unexpected ${request.url.path}');
      }),
    );
    final auth = _auth(api);
    await tester.pumpWidget(_host(GameEntryPage(
      auth: auth,
      game: const {'id': 'game-1', 'name': 'شهر بازی'},
    )));
    await tester.pumpAndSettle();

    expect(
        find.text(
            'چند نوبت از این بازی پیدا شد، اما زمان آن‌ها از سرور نیامده است. از برگزارکننده زمان بازی را بپرس.'),
        findsOneWidget);
    expect(find.text('ورود با این تیم'), findsNothing);
    auth.dispose();
  });
}
