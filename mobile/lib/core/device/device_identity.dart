import 'package:uuid/uuid.dart';

import '../storage/secure_store.dart';

class DeviceIdentity {
  DeviceIdentity(this._store);

  static const _key = 'nexo.device_id';
  final SecureStore _store;

  Future<String> getOrCreate() async {
    final existing = await _store.read(_key);
    if (existing != null && existing.isNotEmpty) {
      return existing;
    }
    final id = const Uuid().v4();
    await _store.write(_key, id);
    return id;
  }
}
