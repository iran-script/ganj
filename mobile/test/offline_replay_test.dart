import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/errors/api_error.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/offline/offline_sync_service.dart';
import 'package:nexo_player/core/offline/outbox_action.dart';
import 'package:nexo_player/core/offline/outbox_repository.dart';

void main() {
  late OfflineIdentity identity;
  late MemoryOutbox outbox;
  late ReplayApi api;
  late OfflineSyncService sync;

  setUp(() {
    identity = OfflineIdentity()..authenticate('user-a', 'device-a');
    identity.bind('session-a', projection());
    outbox = MemoryOutbox(identity);
    api = ReplayApi();
    sync = OfflineSyncService(api: api, outbox: outbox, identity: identity);
  });

  test('original owner is required across user and device switches', () async {
    outbox.records.add(action('one'));
    identity.authenticate('user-b', 'device-a');
    await sync.sync();
    expect(api.posts, isEmpty);
    identity.authenticate('user-a', 'device-b');
    await sync.sync();
    expect(api.posts, isEmpty);
    expect(outbox.records.single.status, 'PENDING');
    identity.authenticate('user-a', 'device-a');
    await sync.sync();
    expect(api.posts, ['one']);
    expect(outbox.records.single.status, 'ACKNOWLEDGED');
  });

  for (final code in [
    'AUTHENTICATION_REQUIRED',
    'DEVICE_NOT_BOUND',
    'PLAYER_DISQUALIFIED',
    'SESSION_FINISHED',
    'SESSION_CANCELLED'
  ]) {
    test('$code stops the batch without sending subsequent actions', () async {
      outbox.records.addAll([action('one'), action('two', sequence: 2)]);
      api.errorCode = code;
      await sync.sync();
      expect(api.posts, ['one']);
      if (code == 'AUTHENTICATION_REQUIRED') {
        expect(identity.authenticationRequired, isTrue);
        await sync.sync();
        expect(api.posts, ['one']);
        expect(outbox.records.first.status, 'PAUSED');
        identity.authenticate('user-a', 'device-a');
        api.errorCode = null;
        await sync.sync();
        expect(api.posts, ['one', 'one', 'two']);
      } else if (code == 'DEVICE_NOT_BOUND') {
        expect(outbox.records.every((a) => a.status == 'PAUSED'), isTrue);
      } else {
        expect(
            outbox.records.every((a) => a.status == 'FAILED_TERMINAL'), isTrue);
      }
    });
  }

  test(
      'failed post-ACK refresh does not return an acknowledged action to replay',
      () async {
    outbox.records.add(action('one'));
    api.failAfterAck = true;
    await sync.sync();
    expect(api.posts, ['one']);
    expect(outbox.records.single.status, 'ACKNOWLEDGED');
    await sync.sync();
    expect(api.posts, ['one']);
  });
  test('device replay remains paused until server binding is restored',
      () async {
    outbox.records.addAll([action('one'), action('two', sequence: 2)]);
    api.deviceBound = false;
    await sync.sync();
    await sync.sync();
    expect(api.posts, isEmpty);
    expect(outbox.records.every((a) => a.status == 'PAUSED'), isTrue);
    api.deviceBound = true;
    await sync.sync();
    expect(api.posts, ['one', 'two']);
    expect(sync.status.userMessage, 'همگام‌سازی انجام شد');
    expect(outbox.records.every((a) => a.status == 'ACKNOWLEDGED'), isTrue);
  });

  test('challenge acknowledgement expires at the server challenge deadline',
      () async {
    outbox.records.add(action('ack', type: 'safe_acknowledgement'));
    api.challengeExpiry = DateTime.utc(2000);
    await sync.sync();
    expect(api.posts, isEmpty);
    expect(outbox.records.single.status, 'FAILED_TERMINAL');
    expect(outbox.records.single.lastError, 'CHALLENGE_EXPIRED');
  });
  test('expired GPS becomes terminal without sending', () async {
    outbox.records.add(action('old', expiresAt: DateTime.utc(2000)));
    await sync.sync();
    expect(api.posts, isEmpty);
    expect(outbox.records.single.lastError, 'EXPIRED_ACTION');
    expect(sync.status.userMessage, contains('منقضی یا رد'));
  });

  test('puzzle expires when node is no longer active', () async {
    outbox.records.add(action('puzzle', type: 'puzzle_submission'));
    api.nodeStatus = 'COMPLETED';
    await sync.sync();
    expect(api.posts, isEmpty);
    expect(outbox.records.single.lastError, 'NODE_NO_LONGER_ACTIVE');
  });

  test('changed team or player context rejects original action', () async {
    outbox.records.add(action('one'));
    api.playerId = 'different-player';
    await sync.sync();
    expect(api.posts, isEmpty);
    expect(outbox.records.single.lastError, 'OWNERSHIP_CONTEXT_CHANGED');
  });

  test('terminal session rejects every stale action before mutation', () async {
    outbox.records.addAll([action('one'), action('two', sequence: 2)]);
    api.sessionStatus = 'CANCELLED';
    await sync.sync();
    expect(api.posts, isEmpty);
    expect(outbox.records.every((a) => a.lastError == 'SESSION_CANCELLED'),
        isTrue);
  });

  test('GPS sends in sequence and retains stable action IDs', () async {
    outbox.records.addAll([action('one'), action('two', sequence: 2)]);
    await sync.sync();
    expect(api.posts, ['one', 'two']);
    expect(sync.status.userMessage, 'همگام‌سازی انجام شد');
    expect(outbox.records.every((a) => a.status == 'ACKNOWLEDGED'), isTrue);
  });

  test('retry backoff prevents later GPS overtaking the first sample',
      () async {
    outbox.records.addAll([
      action('one').copyWith(
          status: 'FAILED_RETRYABLE',
          nextRetryAt: DateTime.now().add(const Duration(hours: 1))),
      action('two', sequence: 2)
    ]);
    await sync.sync();
    expect(api.posts, isEmpty);
  });

  test('QR cannot be added to an outbox', () async {
    await expectLater(
        outbox.enqueue(
            actionType: 'qr_scan', sessionId: 'session-a', payload: {}),
        throwsStateError);
    expect(outbox.records, isEmpty);
  });

  test(
      'logout clears private contexts and legacy unowned action fails ownership',
      () {
    final old = OutboxAction(
        clientActionId: 'legacy',
        actionType: 'gps_sample',
        sessionId: 'session-a',
        payload: {},
        sequence: 1,
        createdAt: DateTime.now());
    expect(old.belongsTo(identity.owner), isFalse);
    identity.clear();
    identity.authenticate('user-b', 'device-a');
    expect(identity.context('session-a'), isNull);
  });
}

Map<String, dynamic> projection(
        {String playerId = 'player-a', String nodeStatus = 'ACTIVE'}) =>
    {
      'session_id': 'session-a',
      'team_id': 'team-a',
      'status': 'ACTIVE',
      'player': {
        'id': playerId,
        'participation_status': 'ACTIVE',
        'device_id': 'bound-device'
      },
      'nodes': [
        {'id': 'node-a', 'status': nodeStatus, 'node_type': 'Puzzle'}
      ],
    };

OutboxAction action(String id,
        {int sequence = 1, String type = 'gps_sample', DateTime? expiresAt}) =>
    OutboxAction(
      clientActionId: id,
      actionType: type,
      sessionId: 'session-a',
      teamId: 'team-a',
      playerId: 'player-a',
      nodeInstanceId: 'node-a',
      ownerUserId: 'user-a',
      ownerDeviceUuid: 'device-a',
      payload:
          type == 'safe_acknowledgement' ? {'challenge_id': 'challenge-a'} : {},
      sequence: sequence,
      createdAt: DateTime.now(),
      expiresAt: expiresAt ?? DateTime.now().add(const Duration(hours: 1)),
    );

class ReplayApi extends ApiClient {
  final posts = <String>[];
  String? errorCode;
  bool failAfterAck = false;
  String sessionStatus = 'RUNNING';
  String nodeStatus = 'ACTIVE';
  String playerId = 'player-a';
  bool deviceBound = true;
  DateTime challengeExpiry = DateTime.now().add(const Duration(hours: 1));

  @override
  Future<String> deviceId() async => 'device-a';

  @override
  Future<Map<String, dynamic>> get(String path, {bool device = false}) async {
    if (failAfterAck && posts.isNotEmpty) {
      throw const SocketException('Refresh unavailable');
    }
    if (path == '/me') return {'id': 'user-a'};
    if (path.startsWith('/devices')) {
      return {
        'items': [
          {
            'id': deviceBound ? 'bound-device' : 'other-device',
            'device_uuid': 'device-a',
            'revoked_at': null
          }
        ]
      };
    }
    if (path.startsWith('/live-challenges')) {
      return {
        'status': 'ACTIVE',
        'expires_at': challengeExpiry.toUtc().toIso8601String()
      };
    }
    if (path.endsWith('/runtime')) {
      return projection(playerId: playerId, nodeStatus: nodeStatus);
    }
    return {'status': sessionStatus};
  }

  @override
  Future<Map<String, dynamic>> post(String path,
      {Map<String, dynamic> body = const {},
      bool device = false,
      bool idempotent = false,
      String? idempotencyKey,
      OfflineOwner? expectedOwner}) async {
    posts.add(idempotencyKey!);
    if (errorCode != null) {
      throw ApiError(code: errorCode!, message: 'Server rejected');
    }
    return {'accepted': true};
  }
}

class MemoryOutbox extends OutboxRepository {
  MemoryOutbox(OfflineIdentity identity) : super(null, identity);
  final records = <OutboxAction>[];

  @override
  Future<List<OutboxAction>> all() async =>
      records.where((a) => a.belongsTo(identity.owner)).toList()
        ..sort((a, b) => a.sequence.compareTo(b.sequence));

  Future<void> change(OutboxAction action, String status,
      [String? error]) async {
    final index =
        records.indexWhere((a) => a.clientActionId == action.clientActionId);
    records[index] = action.copyWith(status: status, lastError: error);
  }

  @override
  Future<void> sending(OutboxAction action) => change(action, 'SENDING');
  @override
  Future<void> acknowledge(OutboxAction action) =>
      change(action, 'ACKNOWLEDGED');
  @override
  Future<void> terminal(OutboxAction action, String error) =>
      change(action, 'FAILED_TERMINAL', error);
  @override
  Future<void> pause(OutboxAction action, String reason) =>
      change(action, 'PAUSED', reason);
  @override
  Future<void> retry(OutboxAction action, String error, Duration delay) =>
      change(action, 'FAILED_RETRYABLE', error);
  @override
  Future<void> prune() async {}
}
