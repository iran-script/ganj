import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/app/app.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/config/app_config.dart';
import 'package:nexo_player/core/auth/auth_controller.dart';
import 'package:nexo_player/core/errors/api_error.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';
import 'package:nexo_player/core/storage/secure_store.dart';

const deviceUuid = '9b93408e-778f-4619-b4f5-c01ea395b895';

void main() {
  testWidgets(
      'failed login preserves the form and displays the credential error',
      (tester) async {
    final response = Completer<http.Response>();
    final auth = AuthController(
        ApiClient(
            store: MemoryStore(),
            client: MockClient((request) => response.future)),
        identity: OfflineIdentity());
    await tester.pumpWidget(NexoApp(authController: auth));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '09120000001');
    await tester.enterText(
        find.byType(TextField).last, 'invalid-test-password');
    await tester.tap(find.byType(FilledButton));
    await tester.pump();
    expect(find.byType(TextField), findsNWidgets(2));
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNull);

    response.complete(http.Response(
        jsonEncode({
          'error': {'code': 'AUTHENTICATION_REQUIRED', 'message': 'invalid'}
        }),
        401));
    await tester.pumpAndSettle();

    expect(find.text('شماره موبایل یا رمز عبور صحیح نیست'), findsOneWidget);
    expect(find.text('09120000001'), findsOneWidget);
    expect(auth.error?.code, 'INVALID_CREDENTIALS');
    expect(auth.error?.statusCode, 401);
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNotNull);
    expect(tester.takeException(), isNull);
    expect(auth.authenticated, isFalse);
  });

  test('valid envelope saves token and restores with the same device headers',
      () async {
    final store = MemoryStore();
    final api = ApiClient(
        store: store,
        client: MockClient((request) async {
          if (request.url.path.endsWith('/auth/login')) {
            expect(request.method, 'POST');
            expect(request.url.toString(),
                '${AppConfig.apiBaseUrl}/auth/login');
            expect(request.headers['accept'], 'application/json');
            expect(request.headers['content-type'], 'application/json');
            expect(jsonDecode(request.body), {
              'mobile': '09120000001',
              'password': 'test-password',
              'device_uuid': deviceUuid,
              'device_name': 'NEXO Player',
              'platform': 'android',
              'app_version': '0.1.0',
            });
            return http.Response(
                jsonEncode({
                  'data': {
                    'token': 'test-token',
                    'user': {
                      'id': 'public-user',
                      'name': 'Test Player',
                      'email': 'player@example.test',
                      'mobile': '09120000001',
                      'status': 'ACTIVE',
                      'roles': ['PLAYER'],
                      'last_login_at': '2026-10-18T10:00:00Z',
                      'created_at': '2026-10-01T10:00:00Z',
                      'updated_at': '2026-10-18T10:00:00Z',
                    },
                    'device': {
                      'id': 'public-device',
                      'device_uuid': deviceUuid,
                      'device_name': 'NEXO Player',
                      'platform': 'android',
                      'app_version': '0.1.0',
                      'last_seen_at': '2026-10-18T10:00:00Z',
                      'revoked_at': null,
                    }
                  }
                }),
                200,
                headers: const {
                  'content-type': 'application/json; charset=utf-8'
                });
          }
          expect(request.url.path, endsWith('/me'));
          expect(request.headers['Authorization'], 'Bearer test-token');
          expect(request.headers['X-Device-Id'], deviceUuid);
          return http.Response(
              '{"data":{"id":"public-user","name":"Test Player"}}', 200);
        }));
    final auth = AuthController(api, identity: OfflineIdentity());

    await auth.login('09120000001', 'test-password');
    expect(auth.authenticated, isTrue);
    expect(await api.token(), 'test-token');
    final restored = AuthController(api, identity: OfflineIdentity());
    await restored.restore();
    expect(restored.user?['id'], 'public-user');
    expect(restored.identity.owner?.deviceUuid, deviceUuid);
    auth.dispose();
    restored.dispose();
  });

  test('network failures produce a safe visible message', () async {
    final auth = AuthController(ApiClient(
        store: MemoryStore(),
        client: MockClient(
            (_) async => throw const SocketException('private diagnostic'))));
    await expectLater(
        auth.login('09120000001', 'test-password'), throwsA(isA<ApiError>()));
    expect(auth.error?.userMessage, 'ارتباط با سرور برقرار نشد');
    expect(auth.signingIn, isFalse);
    expect(auth.authenticated, isFalse);
    auth.dispose();
  });

  test('login maps Laravel 422 validation envelopes', () async {
    final auth = AuthController(ApiClient(
      store: MemoryStore(),
      client: MockClient((_) async => http.Response(
            jsonEncode({
              'error': {
                'code': 'VALIDATION_FAILED',
                'message': 'The request contains invalid fields.',
                'details': {
                  'fields': {
                    'mobile': ['The mobile field format is invalid.']
                  }
                }
              }
            }),
            422,
            headers: const {'content-type': 'application/json'},
          )),
    ));

    await expectLater(
      auth.login('invalid-mobile', 'test-password'),
      throwsA(isA<ApiError>()
          .having((error) => error.statusCode, 'status', 422)
          .having((error) => error.code, 'code', 'VALIDATION_FAILED')),
    );
    expect(auth.error?.fieldMessage('mobile'), isNotNull);
    auth.dispose();
  });

  test('HTML gateway errors become a localized server unavailable error',
      () async {
    final auth = AuthController(ApiClient(
      store: MemoryStore(),
      client: MockClient((_) async => http.Response(
            '<html>Bad Gateway</html>',
            502,
            headers: const {'content-type': 'text/html'},
          )),
    ));

    await expectLater(
      auth.login('09120000001', 'test-password'),
      throwsA(isA<ApiError>()
          .having((error) => error.statusCode, 'status', 502)
          .having((error) => error.code, 'code', 'SERVER_UNAVAILABLE')),
    );
    expect(auth.error?.userMessage, contains('سرویس'));
    auth.dispose();
  });

  test('malformed successful response is an invalid response', () async {
    final auth = AuthController(ApiClient(
      store: MemoryStore(),
      client: MockClient((_) async => http.Response(
            '<html>unexpected</html>',
            200,
            headers: const {'content-type': 'text/html'},
          )),
    ));

    await expectLater(
      auth.login('09120000001', 'test-password'),
      throwsA(isA<ApiError>()
          .having((error) => error.code, 'code', 'INVALID_RESPONSE')),
    );
    auth.dispose();
  });

  test('incomplete successful envelope is an unexpected schema', () async {
    final auth = AuthController(ApiClient(
      store: MemoryStore(),
      client: MockClient((_) async => http.Response(
            '{"data":{"user":{"id":"public-user","name":"Test"}}}',
            200,
            headers: const {'content-type': 'application/json'},
          )),
    ));

    await expectLater(
      auth.login('09120000001', 'test-password'),
      throwsA(isA<ApiError>()
          .having((error) => error.code, 'code', 'UNEXPECTED_RESPONSE_SCHEMA')),
    );
    expect(auth.error?.userMessage, contains('ساختار پاسخ'));
    auth.dispose();
  });

  test('HTTP throttling and service failures use distinct localized errors',
      () async {
    final cases = <int, String>{
      429: 'RATE_LIMITED',
      500: 'SERVER_UNAVAILABLE',
      503: 'SERVER_UNAVAILABLE',
    };

    for (final entry in cases.entries) {
      final auth = AuthController(ApiClient(
        store: MemoryStore(),
        client: MockClient((_) async => http.Response('', entry.key)),
      ));

      await expectLater(
        auth.login('09120000001', 'test-password'),
        throwsA(isA<ApiError>()
            .having((error) => error.statusCode, 'status', entry.key)
            .having((error) => error.code, 'code', entry.value)),
      );
      expect(auth.error?.userMessage, isNotEmpty);
      auth.dispose();
    }
  });

  testWidgets('login timeout displays an error and allows another attempt',
      (tester) async {
    final auth = AuthController(
        ApiClient(
            store: MemoryStore(),
            client: MockClient(
                (_) async => throw TimeoutException('private diagnostic'))),
        identity: OfflineIdentity());
    await tester.pumpWidget(NexoApp(authController: auth));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '09120000001');
    await tester.enterText(find.byType(TextField).last, 'test-password');
    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();

    expect(find.text('ارتباط با سرور برقرار نشد'), findsOneWidget);
    expect(find.byType(TextField), findsNWidgets(2));
    expect(tester.widget<FilledButton>(find.byType(FilledButton)).onPressed,
        isNotNull);
    expect(auth.authenticated, isFalse);
    expect(tester.takeException(), isNull);
  });
  test('unreadable token never authenticates the user', () async {
    final auth = AuthController(ApiClient(
        store: MemoryStore(dropToken: true),
        client: MockClient((_) async => http.Response(
            jsonEncode({
              'data': {
                'token': 'test-token',
                'user': {'id': 'public-user', 'name': 'Test Player'},
                'device': {'device_uuid': deviceUuid}
              }
            }),
            200))));
    await expectLater(
        auth.login('09120000001', 'test-password'), throwsA(isA<ApiError>()));
    expect(auth.error?.code, 'AUTH_STORAGE_FAILED');
    expect(auth.authenticated, isFalse);
    auth.dispose();
  });
}

class MemoryStore extends SecureStore {
  MemoryStore({this.dropToken = false});
  final bool dropToken;
  final values = <String, String>{'nexo.device_id': deviceUuid};
  @override
  Future<String?> read(String key) async => values[key];
  @override
  Future<void> write(String key, String value) async {
    if (!(dropToken && key == 'nexo.access_token')) values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    values.remove(key);
  }
}
