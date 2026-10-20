import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/core/errors/api_error.dart';
import 'package:nexo_player/core/offline/offline_identity.dart';

void main() {
  tearDown(() => OfflineIdentity.instance.clear());

  test('evidence multipart carries device, authorization, source and retry key',
      () async {
    final api = ApiClient(
        store: MediaStore(),
        client: MockClient((request) async {
          expect(request.url.path, endsWith('/runtime/nodes/node/evidence'));
          expect(request.headers['Authorization'], 'Bearer access-token');
          expect(request.headers['X-Device-Id'], MediaStore.device);
          expect(request.headers['Idempotency-Key'], 'stable-retry-key');
          expect(request.headers['content-type'],
              startsWith('multipart/form-data;'));
          expect(request.body, contains('name="capture_source"'));
          expect(request.body, contains('LIBRARY'));
          expect(request.body, contains('filename="proof.pdf"'));
          expect(request.body, contains('%PDF-1.4'));
          return http.Response(
              '{"data":{"id":"submission","status":"PENDING"}}', 201);
        }));
    final result = await api.uploadEvidence(
        'node',
        Uint8List.fromList(utf8.encode('%PDF-1.4')),
        'proof.pdf',
        {'capture_source': 'LIBRARY'},
        'stable-retry-key');
    expect(result['status'], 'PENDING');
  });

  test('authorized media download rejects mismatched MIME and server denial',
      () async {
    for (final response in [
      http.Response('html', 200, headers: {'content-type': 'text/html'}),
      http.Response('denied', 403)
    ]) {
      final api = ApiClient(
          store: MediaStore(), client: MockClient((request) async => response));
      await expectLater(
          api.downloadMedia('/runtime/nodes/node/media',
              expectedMime: 'application/pdf'),
          throwsA(isA<ApiError>()));
    }
  });

  test('media download discards bytes after authentication identity changes',
      () async {
    final api = ApiClient(
        store: MediaStore(),
        client: MockClient((request) async {
          OfflineIdentity.instance.clear();
          return http.Response('%PDF', 200,
              headers: {'content-type': 'application/pdf'});
        }));
    await expectLater(
        api.downloadMedia('/runtime/nodes/node/media',
            expectedMime: 'application/pdf'),
        throwsStateError);
  });

  test('authorized media download returns only the expected content', () async {
    final api = ApiClient(
        store: MediaStore(),
        client: MockClient((request) async {
          expect(request.headers['Authorization'], 'Bearer access-token');
          expect(request.headers['X-Device-Id'], MediaStore.device);
          return http.Response('%PDF', 200,
              headers: {'content-type': 'application/pdf'});
        }));
    expect(
        utf8.decode(await api.downloadMedia('/runtime/nodes/node/media',
            expectedMime: 'application/pdf')),
        '%PDF');
  });

  test('canonical list responses retain items and pagination metadata',
      () async {
    final api = ApiClient(
        store: EmptyStore(),
        client: MockClient((request) async => http.Response(
            '{"data":[{"id":"device-public","device_uuid":"device-a","revoked_at":null}],"meta":{"pagination":{"has_more":false}}}',
            200)));
    final response = await api.get('/devices');
    expect((response['items'] as List).single['id'], 'device-public');
    expect(response['meta']['pagination']['has_more'], isFalse);
  });

  test('maps canonical errors without depending on server message', () {
    expect(
        const ApiError(code: 'DEVICE_NOT_BOUND', message: 'server text')
            .userMessage,
        contains('دستگاه'));
    expect(const ApiError(code: 'QR_REPLAYED', message: '').userMessage,
        contains('قبلاً'));
    expect(const ApiError(code: 'UNKNOWN_ERROR', message: '').userMessage,
        isNotEmpty);
  });

  test('maps HTTP status fallbacks to useful API error codes', () async {
    final expectedCodes = {
      403: 'AUTHORIZATION_DENIED',
      404: 'RESOURCE_NOT_FOUND',
      409: 'CONFLICT',
      422: 'VALIDATION_FAILED',
      503: 'SERVER_UNAVAILABLE',
    };
    for (final entry in expectedCodes.entries) {
      final api = ApiClient(
        store: EmptyStore(),
        client: MockClient((_) async => http.Response('{}', entry.key)),
      );
      await expectLater(
        api.get('/games'),
        throwsA(
          isA<ApiError>().having((error) => error.code, 'code', entry.value),
        ),
      );
    }
  });

  test('keeps backend error codes for server failures', () async {
    final api = ApiClient(
      store: EmptyStore(),
      client: MockClient((_) async => http.Response(
            '{"error":{"code":"RUNTIME_BOOTSTRAP_FAILED","message":"Unavailable"}}',
            500,
          )),
    );
    await expectLater(
      api.get('/game-sessions'),
      throwsA(isA<ApiError>().having(
        (error) => error.code,
        'code',
        'RUNTIME_BOOTSTRAP_FAILED',
      )),
    );
  });
}

class EmptyStore extends SecureStore {
  @override
  Future<String?> read(String key) async => null;
}

class MediaStore extends SecureStore {
  static const device = '00000000-0000-4000-8000-000000000001';
  @override
  Future<String?> read(String key) async =>
      key == 'nexo.device_id' ? device : 'access-token';
}
