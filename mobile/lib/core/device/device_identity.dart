import 'package:uuid/uuid.dart';
import 'package:flutter/foundation.dart';
import '../errors/api_error.dart';

import '../storage/secure_store.dart';

class DeviceIdentity {
  DeviceIdentity(this._store);

  static const _key = 'nexo.device_id';
  final SecureStore _store;

  Future<String> getOrCreate() async {
    final existing = await _store.read(_key);
    if (existing != null && existing.isNotEmpty) {
      final valid = RegExp(
              r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$')
          .hasMatch(existing);
      if (kDebugMode) debugPrint('NEXO device_uuid valid=$valid');
      if (!valid) throw const ApiError(code: 'DEVICE_ID_INVALID', message: '');
      return existing;
    }
    final id = const Uuid().v4();
    await _store.write(_key, id);
    if (kDebugMode) debugPrint('NEXO device_uuid valid=true');
    return id;
  }
}
