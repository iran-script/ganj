import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/app/nexo_theme.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:nexo_player/features/hints/hints_panel.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/offline/safe_cache.dart';
import 'package:nexo_player/features/location/location_page.dart';
import 'package:nexo_player/features/qr/qr_scanner_page.dart';
import 'package:nexo_player/features/runtime/runtime_repository.dart';

void main() {
  tearDown(() => OfflineIdentity.instance.clear());

  test('runtime projection includes authoritative game session status',
      () async {
    final paths = <String>[];
    final api = ApiClient(
        store: _TestStore(),
        client: MockClient((request) async {
          paths.add(request.url.path);
          final data = request.url.path.endsWith('/state')
              ? {'status': 'PAUSED'}
              : {
                  'session_id': 'session',
                  'status': 'ACTIVE',
                  'team_id': 'team',
                  'nodes': [],
                  'active_node_ids': [],
                };
          return http.Response(jsonEncode({'data': data}), 200);
        }));
    final repository = RuntimeRepository(api, null, _NoopSafeCache());

    final projection = await repository.projection('session');

    expect(projection['status'], 'ACTIVE');
    expect(projection['session_status'], 'PAUSED');
    expect(paths, contains('/api/v1/game-sessions/session/players/me/runtime'));
    expect(paths, contains('/api/v1/game-sessions/session/state'));
  });

  testWidgets('ambiguous hint retry reuses the same idempotency key',
      (tester) async {
    final keys = <String?>[];
    var calls = 0;
    final api = ApiClient(
        store: _TestStore(),
        client: MockClient((request) async {
          calls++;
          keys.add(request.headers['Idempotency-Key']);
          if (calls == 1) throw http.ClientException('connection lost');
          return http.Response(
              jsonEncode({
                'data': {'content': 'verified server hint'}
              }),
              200);
        }));

    await tester.pumpWidget(MaterialApp(
        theme: NexoTheme.data,
        home: Directionality(
            textDirection: TextDirection.rtl,
            child: Scaffold(
                body: HintsPanel(
                    nodeId: 'node',
                    api: api,
                    createIdempotencyKey: () => 'stable-hint-request')))));

    await tester.tap(find.byType(OutlinedButton));
    await tester.pumpAndSettle();
    expect(find.byType(NexoNotice), findsOneWidget);
    expect(find.byType(OutlinedButton), findsOneWidget);

    await tester.tap(find.byType(OutlinedButton));
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(keys, ['stable-hint-request', 'stable-hint-request']);
    expect(find.text('verified server hint'), findsOneWidget);
  });

  testWidgets('GPS sample outside server accuracy policy is not submitted',
      (tester) async {
    OfflineIdentity.instance
        .authenticate('user', '00000000-0000-4000-8000-000000000001');
    final requests = <http.Request>[];
    var missionCallbackCalled = false;
    final api = ApiClient(
        store: _TestStore(),
        client: MockClient((request) async {
          requests.add(request);
          return http.Response(
              '{"data":{"active":true,"min_accuracy":15}}', 200);
        }));

    await tester.pumpWidget(MaterialApp(
        theme: NexoTheme.data,
        home: LocationPage(
            sessionId: 'session',
            api: api,
            gateway: _TestLocationGateway(),
            onLocation: (_) async => missionCallbackCalled = true)));
    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();

    expect(requests.map((request) => request.url.path),
        ['/api/v1/game-sessions/session/players/me/location-policy']);
    expect(missionCallbackCalled, isFalse);
  });

  testWidgets(
      'QR scan ignores duplicate camera callbacks and uses server token',
      (tester) async {
    final requests = <http.Request>[];
    final receivedTokens = <String>[];
    final api = ApiClient(
        store: _TestStore(),
        client: MockClient((request) async {
          requests.add(request);
          return http.Response(
              '{"data":{"accepted":true,"one_time_token":"server-token"}}',
              200);
        }));
    await tester.pumpWidget(MaterialApp(
        theme: NexoTheme.data,
        home: QrScannerPage(
            sessionId: 'session',
            api: api,
            onValue: (value) async => receivedTokens.add(value),
            scannerBuilder: (onDetected) => Center(
                child: IconButton(
                    onPressed: () {
                      onDetected('raw-qr-value');
                      onDetected('raw-qr-value');
                    },
                    icon: const Icon(Icons.qr_code_scanner))))));

    await tester.tap(find.byType(IconButton));
    await tester.pumpAndSettle();
    expect(requests, hasLength(1));
    expect(requests.single.url.path, '/api/v1/qr/scan');
    expect(requests.single.body, contains('raw-qr-value'));
    expect(receivedTokens, ['server-token']);
    expect(find.byType(FilledButton), findsOneWidget);
  });

  testWidgets(
      'GPS protected QR scanner opens only after server proximity passes',
      (tester) async {
    final paths = <String>[];
    final api = ApiClient(
        store: _TestStore(),
        client: MockClient((request) async {
          paths.add(request.url.path);
          final data = switch (request.url.path) {
            '/api/v1/game-sessions/session/players/me/location-policy' => {
                'active': true,
                'min_accuracy': 100
              },
            '/api/v1/locations' => {'accepted': true},
            '/api/v1/qr/proximity' => {
                'inside': true,
                'distance_meters': 4,
                'accuracy_meters': 5
              },
            '/api/v1/qr/scan' => {
                'accepted': true,
                'one_time_token': 'server-token'
              },
            _ => <String, dynamic>{},
          };
          return http.Response(jsonEncode({'data': data}), 200);
        }));
    final received = <String>[];
    await tester.pumpWidget(MaterialApp(
      theme: NexoTheme.data,
      home: QrScannerPage(
        sessionId: 'session',
        requireFreshLocation: true,
        api: api,
        locationGateway: _TestLocationGateway(),
        onValue: (value) async => received.add(value),
        scannerBuilder: (onDetected) => IconButton(
          onPressed: () => onDetected('qr-payload'),
          icon: const Icon(Icons.qr_code_scanner),
        ),
      ),
    ));

    expect(find.byType(IconButton), findsNothing);
    await tester.tap(find.text('بررسی موقعیت و بازکردن دوربین'));
    await tester.pumpAndSettle();
    expect(find.byType(IconButton), findsOneWidget);
    await tester.tap(find.byType(IconButton));
    await tester.pumpAndSettle();
    expect(paths, [
      '/api/v1/game-sessions/session/players/me/location-policy',
      '/api/v1/locations',
      '/api/v1/qr/proximity',
      '/api/v1/game-sessions/session/players/me/location-policy',
      '/api/v1/locations',
      '/api/v1/qr/proximity',
      '/api/v1/qr/scan',
    ]);
    expect(received, ['server-token']);
  });
}

class _TestLocationGateway implements LocationGateway {
  @override
  Future<bool> serviceEnabled() async => true;

  @override
  Future<LocationPermission> checkPermission() async =>
      LocationPermission.whileInUse;

  @override
  Future<LocationPermission> requestPermission() async =>
      LocationPermission.whileInUse;

  @override
  Future<Position> currentPosition() async => Position(
      longitude: 51.4,
      latitude: 35.7,
      timestamp: DateTime.now().toUtc(),
      accuracy: 50,
      altitude: 0,
      altitudeAccuracy: 0,
      heading: 0,
      headingAccuracy: 0,
      speed: 0,
      speedAccuracy: 0);
}

class _TestStore extends SecureStore {
  @override
  Future<String?> read(String key) async =>
      key == 'nexo.device_id' ? '00000000-0000-4000-8000-000000000001' : null;
}

class _NoopSafeCache extends SafeCache {
  @override
  Future<void> saveRuntime(
      String sessionId, Map<String, dynamic> projection) async {}

  @override
  Future<Map<String, dynamic>?> runtime(String sessionId) async => null;
}
