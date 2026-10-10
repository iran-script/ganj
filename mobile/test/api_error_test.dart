import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/core/errors/api_error.dart';

void main() {
  test('maps canonical errors without depending on server message', () {
    expect(const ApiError(code: 'DEVICE_NOT_BOUND', message: 'server text').userMessage, contains('دستگاه'));
    expect(const ApiError(code: 'QR_REPLAYED', message: '').userMessage, contains('قبلاً'));
    expect(const ApiError(code: 'UNKNOWN_ERROR', message: '').userMessage, isNotEmpty);
  });
}
