import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/app/nexo_theme.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/offline/local_database.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/teams/team_invitations_page.dart';
import 'package:nexo_player/features/teams/team_repository.dart';

const playerId = '28d543bb-2926-45f2-9625-184afeb5c740';
const inviteId = 'bbce2488-7601-44c2-8c7e-642f20fc9b5c';
const teamId = 'c2d77457-5245-4d25-96b6-032fe70c9a20';

class _Store extends SecureStore {
  @override
  Future<String?> read(String key) async => switch (key) {
        'nexo.device_id' => '9b93408e-778f-4619-b4f5-c01ea395b895',
        'nexo.access_token' => 'test-token',
        _ => null,
      };
}

class _Database extends LocalDatabase {
  @override
  Future<void> clearCache() async {}
}

Map<String, dynamic> envelope(Object data) => {'data': data};

http.Response jsonResponse(Object data, [int status = 200]) => http.Response(
      jsonEncode(envelope(data)),
      status,
      headers: const {'content-type': 'application/json'},
    );

void main() {
  testWidgets(
      'invitation page groups sent invitations and permits captain revoke',
      (tester) async {
    var revoked = false;
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path == '/api/v1/me/team-invitations') {
          return jsonResponse([]);
        }
        if (request.url.path == '/api/v1/teams') {
          return jsonResponse([
            {
              'id': teamId,
              'name': 'تیم آزمایش',
              'status': 'ACTIVE',
              'captain_user_id': playerId,
              'captain': {'id': playerId, 'name': 'کاپیتان'},
              'members': [
                {'user_id': playerId, 'name': 'کاپیتان', 'status': 'ACTIVE'}
              ],
            }
          ]);
        }
        if (request.url.path == '/api/v1/teams/$teamId/invites' &&
            request.method == 'GET') {
          return jsonResponse(revoked
              ? []
              : [
                  {
                    'id': inviteId,
                    'recipient': {
                      'id': 'public-player',
                      'name': 'بازیکن مهمان'
                    },
                    'decision': 'PENDING',
                    'expires_at': '2099-11-01T12:00:00Z',
                  }
                ]);
        }
        if (request.url.path == '/api/v1/teams/$teamId/invites/$inviteId' &&
            request.method == 'DELETE') {
          revoked = true;
          return http.Response('', 204);
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = AuthController(
      api,
      database: _Database(),
      identity: OfflineIdentity(),
    )..user = {'id': playerId, 'name': 'کاپیتان'};

    await tester.pumpWidget(MaterialApp(
      theme: NexoTheme.data,
      home: TeamInvitationsPage(auth: controller),
    ));
    await tester.pumpAndSettle();
    expect(find.text('دریافتی'), findsOneWidget);
    expect(find.text('ارسالی'), findsOneWidget);
    await tester.tap(find.text('ارسالی'));
    await tester.pumpAndSettle();
    expect(find.text('بازیکن مهمان'), findsOneWidget);
    expect(find.textContaining('در انتظار پاسخ'), findsOneWidget);
    await tester.tap(find.byTooltip('لغو دعوت'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'لغو دعوت'));
    await tester.pumpAndSettle();

    expect(revoked, isTrue);
    expect(find.text('دعوت ارسالی ندارید'), findsOneWidget);
    expect(tester.takeException(), isNull);
    controller.dispose();
  });

  test('outgoing invitations use the team invite list and revoke API',
      () async {
    final requests = <http.Request>[];
    final repository = TeamRepository(ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        requests.add(request);
        if (request.method == 'GET') {
          return jsonResponse([
            {
              'id': inviteId,
              'recipient': {'id': playerId, 'name': 'بازیکن آزمایشی'},
              'decision': 'PENDING',
              'expires_at': '2099-11-01T12:00:00Z',
              'revoked_at': null,
            }
          ]);
        }
        return http.Response('', 204);
      }),
    ));

    final invitations = await repository.outgoingInvitations(teamId);
    expect(invitations.single.recipientName, 'بازیکن آزمایشی');
    expect(invitations.single.isPending, isTrue);
    await repository.revokeInvitation(teamId, inviteId);

    expect(requests.map((request) => request.method), ['GET', 'DELETE']);
    expect(requests.first.url.path, '/api/v1/teams/$teamId/invites');
    expect(requests.last.url.path, '/api/v1/teams/$teamId/invites/$inviteId');
  });

  testWidgets('captain can review and revoke a pending sent invitation',
      (tester) async {
    var revoked = false;
    var listCalls = 0;
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.method == 'GET') {
          listCalls++;
          return jsonResponse([
            {
              'id': inviteId,
              'recipient': {'id': playerId, 'name': 'بازیکن آزمایشی'},
              'decision': 'PENDING',
              'revoked_at': revoked ? '2026-10-20T12:00:00Z' : null,
            }
          ]);
        }
        if (request.method == 'DELETE') {
          revoked = true;
          return http.Response('', 204);
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = AuthController(
      api,
      database: _Database(),
      identity: OfflineIdentity(),
    );

    await tester.pumpWidget(MaterialApp(
      theme: NexoTheme.data,
      home: SentTeamInvitationsPage(
        auth: controller,
        teamId: teamId,
        teamName: 'تیم آزمایشی',
      ),
    ));
    await tester.pumpAndSettle();
    expect(find.text('بازیکن آزمایشی'), findsOneWidget);
    await tester.tap(find.byTooltip('لغو دعوت'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'لغو دعوت'));
    await tester.pumpAndSettle();

    expect(revoked, isTrue);
    expect(listCalls, 2);
    expect(find.text('لغوشده'), findsOneWidget);
    expect(find.byTooltip('لغو دعوت'), findsNothing);
    expect(tester.takeException(), isNull);
    controller.dispose();
  });

  test(
      'team repository searches by public ID, sends invitation, and lists incoming invitations',
      () async {
    final seen = <http.Request>[];
    final repository = TeamRepository(ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        seen.add(request);
        if (request.url.path == '/api/v1/players/$playerId') {
          return jsonResponse(
              {'id': playerId, 'name': 'ط¨ط§ط²غŒع©ظ† ط¢ط²ظ…ط§غŒط´غŒ'});
        }
        if (request.url.path == '/api/v1/teams/$teamId/player-invitations') {
          expect(jsonDecode(request.body), {'user_id': playerId});
          return jsonResponse({'id': inviteId, 'decision': 'PENDING'}, 201);
        }
        if (request.url.path == '/api/v1/me/team-invitations') {
          return jsonResponse([
            {
              'id': inviteId,
              'team': {'id': teamId, 'name': 'طھغŒظ… ط¢ط²ظ…ط§غŒط´غŒ'},
              'captain': {
                'id': playerId,
                'name': 'ط³ط±ظ¾ط±ط³طھ ط¢ط²ظ…ط§غŒط´غŒ'
              },
              'status': 'PENDING',
            }
          ]);
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    ));

    final player = await repository.findPlayer(playerId);
    expect(player.keys, {'id', 'name'});
    expect(player['id'], playerId);
    await repository.invitePlayer(teamId, playerId);
    final invitations = await repository.incomingInvitations();

    expect(invitations.single.teamName, 'طھغŒظ… ط¢ط²ظ…ط§غŒط´غŒ');
    expect(invitations.single.status, 'PENDING');
    expect(seen[1].headers['Idempotency-Key'], isNotEmpty);
    expect(seen[2].headers['Authorization'], 'Bearer test-token');
  });

  testWidgets(
      'incoming invitation can be accepted and refreshes to accepted status',
      (tester) async {
    var decision = 'PENDING';
    var accepted = 0;
    final api = ApiClient(
      store: _Store(),
      client: MockClient((request) async {
        if (request.url.path == '/api/v1/teams' && request.method == 'GET') {
          return jsonResponse([]);
        }
        if (request.url.path == '/api/v1/me/team-invitations' &&
            request.method == 'GET') {
          return jsonResponse([
            {
              'id': inviteId,
              'team': {'id': teamId, 'name': 'طھغŒظ… ط´ط¨ع¯ط±ط¯'},
              'captain': {'id': playerId, 'name': 'ط¢ط±ظ…ط§ظ†'},
              'status': decision,
            }
          ]);
        }
        if (request.url.path ==
                '/api/v1/me/team-invitations/$inviteId/accept' &&
            request.method == 'POST') {
          accepted++;
          decision = 'ACCEPTED';
          return jsonResponse({'id': inviteId, 'status': decision});
        }
        throw StateError('Unexpected ${request.method} ${request.url.path}');
      }),
    );
    final controller = AuthController(
      api,
      database: _Database(),
      identity: OfflineIdentity(),
    );

    await tester.pumpWidget(MaterialApp(
      theme: NexoTheme.data,
      home: TeamInvitationsPage(auth: controller),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();

    expect(accepted, 1);
    expect(find.byType(FilledButton), findsNothing);
    expect(find.byType(OutlinedButton), findsNothing);
    expect(tester.takeException(), isNull);
    controller.dispose();
  });
}
