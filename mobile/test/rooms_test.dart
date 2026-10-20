import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/errors/api_error.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/rooms/room_pages.dart';
import 'package:nexo_player/features/rooms/room_repository.dart';

class _MemoryStore extends SecureStore {
  final Map<String, String> values = {};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}

Map<String, dynamic> roomJson(
        {String status = 'OPEN',
        int teams = 1,
        List<Map<String, dynamic>> teamData = const []}) =>
    {
      'id': '8ba17bf6-1d3e-4f05-a0a7-21e078b80d4f',
      'game_id': '77b30ca7-b55e-40c2-a0a7-21e078b80d4f',
      'game_name': 'City game',
      'city': 'Tehran',
      'name': 'North room',
      'status': status,
      'session_status': 'REGISTRATION',
      'capacity': 5,
      'team_count': teams,
      'teams': teamData,
    };

Map<String, dynamic> myTeamJson() => {
      'id': 'bdc970a0-e5bd-432a-9877-a5e3e1e7610f',
      'name': 'تیم من',
      'status': 'ACTIVE',
      'captain_user_id': 'captain-public-id',
      'captain': {'id': 'captain-public-id', 'name': 'سرپرست'},
      'members': [
        for (var i = 0; i < 4; i++) {'user_id': 'member-$i'}
      ],
      'member_count': 4,
      'eligible_for_game': true,
    };

http.Response _json(Object data, [int status = 200]) => http.Response(
      jsonEncode({'data': data}),
      status,
      headers: const {'content-type': 'application/json'},
    );

void main() {
  testWidgets('captain can create a room with eligible team and retry safely',
      (tester) async {
    var createAttempts = 0;
    String? firstKey;
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path.endsWith(
                '/games/77b30ca7-b55e-40c2-a0a7-21e078b80d4f/rooms') &&
            request.method == 'GET') {
          return _json([
            createAttempts >= 2
                ? {...roomJson(), 'name': 'اتاق تازه'}
                : roomJson(),
          ]);
        }
        if (request.url.path.endsWith('/teams') && request.method == 'GET') {
          return _json([myTeamJson()]);
        }
        expect(request.method, 'POST');
        expect(request.url.path,
            endsWith('/games/77b30ca7-b55e-40c2-a0a7-21e078b80d4f/rooms'));
        expect(jsonDecode(request.body), {
          'name': 'اتاق تازه',
          'team_id': 'bdc970a0-e5bd-432a-9877-a5e3e1e7610f',
        });
        createAttempts++;
        firstKey ??= request.headers['Idempotency-Key'];
        expect(request.headers['Idempotency-Key'], firstKey);
        if (createAttempts == 1) {
          return http.Response(
            jsonEncode({
              'error': {'code': 'NETWORK_ERROR', 'message': 'Unavailable'}
            }),
            503,
            headers: const {'content-type': 'application/json'},
          );
        }
        return _json({...roomJson(), 'name': 'اتاق تازه'});
      }),
    );
    final auth = AuthController(api)..user = {'id': 'captain-public-id'};
    await tester.pumpWidget(MaterialApp(
      home: RoomListPage(
        auth: auth,
        game: {'id': '77b30ca7-b55e-40c2-a0a7-21e078b80d4f', 'name': 'بازی'},
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'ساخت اتاق').first);
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField), 'اتاق تازه');
    await tester.tap(find.text('ساخت اتاق').last);
    await tester.pumpAndSettle();
    expect(find.textContaining('ساخت اتاق انجام نشد'), findsOneWidget);
    await tester.tap(find.text('ساخت اتاق').last);
    await tester.pumpAndSettle();

    expect(createAttempts, 2);
    expect(firstKey, isNotEmpty);
    expect(find.text('اتاق تازه'), findsOneWidget);
    auth.dispose();
  });

  testWidgets('captain can add an eligible own team to an open room',
      (tester) async {
    var addRequests = 0;
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path
                .endsWith('/rooms/8ba17bf6-1d3e-4f05-a0a7-21e078b80d4f') &&
            request.method == 'GET') {
          return _json(roomJson());
        }
        if (request.url.path.endsWith('/teams') && request.method == 'GET') {
          return _json([myTeamJson()]);
        }
        expect(request.method, 'POST');
        expect(request.url.path,
            endsWith('/rooms/8ba17bf6-1d3e-4f05-a0a7-21e078b80d4f/teams'));
        expect(jsonDecode(request.body),
            {'team_id': 'bdc970a0-e5bd-432a-9877-a5e3e1e7610f'});
        expect(request.headers['Idempotency-Key'], isNotEmpty);
        addRequests++;
        return _json(roomJson(teams: 2));
      }),
    );
    final auth = AuthController(api)..user = {'id': 'captain-public-id'};
    await tester.pumpWidget(MaterialApp(
      home: RoomDetailPage(
        auth: auth,
        roomId: '8ba17bf6-1d3e-4f05-a0a7-21e078b80d4f',
        repository: RoomRepository(api),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('افزودن تیم خودم'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('تیم من'));
    await tester.pumpAndSettle();

    expect(addRequests, 1);
    auth.dispose();
  });

  testWidgets('room and team details show Persian statuses and real capacity',
      (tester) async {
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path
            .endsWith('/games/77b30ca7-b55e-40c2-a0a7-21e078b80d4f/rooms')) {
          return _json([roomJson()]);
        }
        if (request.url.path
            .endsWith('/rooms/8ba17bf6-1d3e-4f05-a0a7-21e078b80d4f')) {
          return _json(roomJson(teamData: [
            {
              'id': 'team-public-id',
              'name': 'تیم نمونه',
              'captain': 'سرپرست',
              'member_count': 6,
              'eligible': true,
              'actor_is_member': false,
            }
          ]));
        }
        throw StateError('Unexpected request: ${request.url.path}');
      }),
    );
    final auth = AuthController(api)..user = {'id': 'player-public-id'};
    await tester.pumpWidget(MaterialApp(
      home: RoomListPage(
        auth: auth,
        game: {'id': '77b30ca7-b55e-40c2-a0a7-21e078b80d4f', 'name': 'بازی'},
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.textContaining('عضوپذیر'), findsOneWidget);
    await tester.tap(find.text('North room'));
    await tester.pumpAndSettle();

    expect(find.text('تیم نمونه'), findsOneWidget);
    expect(find.text('اعضا: ۶ از ۸ نفر'), findsOneWidget);
    expect(find.textContaining('عضوپذیر'), findsWidgets);
    expect(find.textContaining('team-public-id'), findsNothing);
    auth.dispose();
  });

  testWidgets('joining from a room uses the authorized team invite endpoint',
      (tester) async {
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path
            .endsWith('/rooms/8ba17bf6-1d3e-4f05-a0a7-21e078b80d4f')) {
          return _json(roomJson());
        }
        expect(request.method, 'POST');
        expect(request.url.path, endsWith('/teams/join'));
        expect(jsonDecode(request.body), {'invite_code': 'invite-123'});
        expect(request.headers['Idempotency-Key'], isNotEmpty);
        return _json({
          'id': 'team-public-id',
          'name': 'تیم مقصد',
          'status': 'ACTIVE',
          'captain_user_id': 'captain-public-id',
          'captain': {'name': 'سرپرست'},
          'members': const [],
          'eligible_for_game': true,
        });
      }),
    );
    final auth = AuthController(api)..user = {'id': 'player-public-id'};
    await tester.pumpWidget(MaterialApp(
      home: RoomDetailPage(
        auth: auth,
        roomId: '8ba17bf6-1d3e-4f05-a0a7-21e078b80d4f',
        repository: RoomRepository(api),
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('عضویت در تیم با کد دعوت'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'invite-123');
    await tester.tap(find.text('عضویت').last);
    await tester.pumpAndSettle();

    expect(find.text('به تیم «تیم مقصد» پیوستید.'), findsOneWidget);
    auth.dispose();
  });

  test('room repository lists and parses server room status and teams',
      () async {
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        expect(request.method, 'GET');
        expect(request.url.path,
            endsWith('/games/77b30ca7-b55e-40c2-a0a7-21e078b80d4f/rooms'));
        return _json([roomJson(status: 'FULL', teams: 5)]);
      }),
    );

    final rooms =
        await RoomRepository(api).list('77b30ca7-b55e-40c2-a0a7-21e078b80d4f');

    expect(rooms.single.status, 'FULL');
    expect(rooms.single.sessionStatus, 'REGISTRATION');
    expect(rooms.single.teamCount, 5);
    expect(rooms.single.city, 'Tehran');
  });

  test('empty room response is empty state data and sends bearer auth only',
      () async {
    final store = _MemoryStore()
      ..values['nexo.access_token'] = 'test-player-token';
    final api = ApiClient(
      store: store,
      client: MockClient((request) async {
        expect(request.method, 'GET');
        expect(request.url.path,
            '/api/v1/games/77b30ca7-b55e-40c2-a0a7-21e078b80d4f/rooms');
        expect(request.headers['Authorization'], 'Bearer test-player-token');
        expect(request.headers.containsKey('X-Device-Id'), isFalse);
        return _json([]);
      }),
    );

    expect(
      await RoomRepository(api).list('77b30ca7-b55e-40c2-a0a7-21e078b80d4f'),
      isEmpty,
    );
  });

  test('room repository rejects a non-list data shape instead of showing empty',
      () async {
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((_) async => _json({'rooms': const []})),
    );

    await expectLater(
      RoomRepository(api).list('77b30ca7-b55e-40c2-a0a7-21e078b80d4f'),
      throwsA(isA<ApiError>()),
    );
  });

  testWidgets('empty room list renders an empty state instead of a failure',
      (tester) async {
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        if (request.url.path.endsWith('/rooms')) return _json([]);
        if (request.url.path.endsWith('/teams')) return _json([]);
        throw StateError('Unexpected request path: ${request.url.path}');
      }),
    );
    final auth = AuthController(api)..user = {'id': 'captain-public-id'};

    await tester.pumpWidget(MaterialApp(
      home: RoomListPage(
        auth: auth,
        game: {
          'id': '77b30ca7-b55e-40c2-a0a7-21e078b80d4f',
          'name': 'City game',
        },
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byIcon(Icons.meeting_room_outlined), findsOneWidget);
    expect(find.byIcon(Icons.error_outline), findsNothing);
    auth.dispose();
  });

  test('room mutations send the requested payload and stable idempotency key',
      () async {
    final api = ApiClient(
      store: _MemoryStore(),
      client: MockClient((request) async {
        expect(request.method, 'POST');
        expect(request.headers['Idempotency-Key'], 'operation-key-001');
        if (request.url.path
            .endsWith('/games/77b30ca7-b55e-40c2-a0a7-21e078b80d4f/rooms')) {
          expect(jsonDecode(request.body), {
            'name': 'North room',
            'team_id': 'bdc970a0-e5bd-432a-9877-a5e3e1e7610f',
          });
        } else {
          expect(request.url.path,
              endsWith('/rooms/8ba17bf6-1d3e-4f05-a0a7-21e078b80d4f/teams'));
          expect(jsonDecode(request.body),
              {'team_id': 'bdc970a0-e5bd-432a-9877-a5e3e1e7610f'});
        }
        return _json(roomJson(teams: 2));
      }),
    );
    final repository = RoomRepository(api);

    final created = await repository.create(
      '77b30ca7-b55e-40c2-a0a7-21e078b80d4f',
      'North room',
      'bdc970a0-e5bd-432a-9877-a5e3e1e7610f',
      'operation-key-001',
    );
    final joined = await repository.addTeam(
      created.id,
      'bdc970a0-e5bd-432a-9877-a5e3e1e7610f',
      'operation-key-001',
    );

    expect(joined.teamCount, 2);
  });
}
