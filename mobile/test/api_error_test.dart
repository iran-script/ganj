import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/storage/secure_store.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/core/errors/api_error.dart';

void main() {
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
}

class EmptyStore extends SecureStore {
  @override
  Future<String?> read(String key) async => null;
}
