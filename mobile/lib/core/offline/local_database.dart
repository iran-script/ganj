import 'dart:convert';

import 'package:path/path.dart' as path;
import 'package:sqflite/sqflite.dart';

import 'outbox_action.dart';
import 'offline_identity.dart';

class LocalDatabase {
  LocalDatabase({this.databasePath});

  static final LocalDatabase instance = LocalDatabase();
  final String? databasePath;
  Database? _database;

  Future<Database> get database async {
    if (_database != null) return _database!;
    final root = await getDatabasesPath();
    _database = await openDatabase(
        databasePath ?? path.join(root, 'nexo_player.db'),
        version: 2, onUpgrade: (db, oldVersion, newVersion) async {
      await _ownershipUpgrade(db);
    }, onCreate: (db, version) async {
      await db.execute('''
        CREATE TABLE outbox_actions (
          client_action_id TEXT PRIMARY KEY,
          action_type TEXT NOT NULL,
          session_id TEXT NOT NULL,
          team_id TEXT,
          node_instance_id TEXT,
          payload TEXT NOT NULL,
          sequence INTEGER NOT NULL,
          created_at TEXT NOT NULL,
          retry_count INTEGER NOT NULL DEFAULT 0,
          next_retry_at TEXT,
          status TEXT NOT NULL,
          last_error TEXT
        )
      ''');
      await db.execute(
          'CREATE INDEX outbox_ready_index ON outbox_actions(status, next_retry_at, sequence)');
      await db.execute('''
        CREATE TABLE safe_cache (
          cache_key TEXT PRIMARY KEY,
          payload TEXT NOT NULL,
          revision TEXT,
          saved_at TEXT NOT NULL
        )
      ''');
      await _ownershipUpgrade(db);
    });
    return _database!;
  }

  Future<void> close() async {
    await _database?.close();
    _database = null;
  }

  static Future<void> _ownershipUpgrade(Database db) async {
    for (final column in [
      'owner_user_id',
      'owner_device_uuid',
      'player_id',
      'expires_at'
    ]) {
      await db.execute('ALTER TABLE outbox_actions ADD COLUMN $column TEXT');
    }
    await db.execute(
        'CREATE TABLE outbox_sequences (stream_key TEXT PRIMARY KEY, sequence INTEGER NOT NULL)');
    await db.execute(
        'CREATE INDEX outbox_owner_index ON outbox_actions(owner_user_id, owner_device_uuid, session_id, sequence)');
    await db.update(
        'outbox_actions',
        {
          'status': 'FAILED_TERMINAL',
          'last_error': 'LEGACY_OWNERSHIP_UNKNOWN',
          'next_retry_at': null,
        },
        where: 'owner_user_id IS NULL OR owner_device_uuid IS NULL');
    await db.delete('safe_cache');
  }

  Future<OutboxAction> insertAction(OutboxAction action) async {
    if (action.ownerUserId == null ||
        action.ownerDeviceUuid == null ||
        action.teamId == null ||
        action.playerId == null ||
        action.expiresAt == null) {
      throw StateError('Unowned actions cannot enter the replay queue.');
    }
    final db = await database;
    return db.transaction((txn) async {
      final existing = await txn.query('outbox_actions',
          where: 'client_action_id = ?', whereArgs: [action.clientActionId]);
      if (existing.isNotEmpty) {
        final saved = _actionFromRow(existing.single);
        if (!saved.belongsTo(
                OfflineOwner(action.ownerUserId!, action.ownerDeviceUuid!)) ||
            saved.sessionId != action.sessionId ||
            saved.teamId != action.teamId ||
            saved.playerId != action.playerId ||
            saved.nodeInstanceId != action.nodeInstanceId ||
            saved.actionType != action.actionType ||
            jsonEncode(saved.payload) != jsonEncode(action.payload)) {
          throw StateError(
              'A client action ID cannot change ownership or content.');
        }
        return saved;
      }
      final stream = jsonEncode(
          [action.ownerUserId, action.ownerDeviceUuid, action.sessionId]);
      final rows = await txn.query('outbox_sequences',
          where: 'stream_key = ?', whereArgs: [stream]);
      final sequence = rows.isEmpty ? 1 : (rows.single['sequence']! as int) + 1;
      await txn.insert(
          'outbox_sequences', {'stream_key': stream, 'sequence': sequence},
          conflictAlgorithm: ConflictAlgorithm.replace);
      final values = {...action.toMap(), 'sequence': sequence};
      await txn.insert(
          'outbox_actions', {...values, 'payload': jsonEncode(action.payload)});
      return OutboxAction.fromMap(values);
    });
  }

  Future<List<OutboxAction>> readyActions(OfflineOwner owner,
      {DateTime? now}) async {
    final db = await database;
    final rows = await db.query('outbox_actions',
        where:
            'owner_user_id = ? AND owner_device_uuid = ? AND status IN (?, ?, ?) AND (next_retry_at IS NULL OR next_retry_at <= ?)',
        whereArgs: [
          owner.userId,
          owner.deviceUuid,
          'PENDING',
          'FAILED_RETRYABLE',
          'SENDING',
          (now ?? DateTime.now()).toUtc().toIso8601String()
        ],
        orderBy: 'sequence ASC');
    return rows.map(_actionFromRow).toList();
  }

  Future<List<OutboxAction>> allActions(OfflineOwner owner) async {
    final db = await database;
    final rows = await db.query('outbox_actions',
        where: 'owner_user_id = ? AND owner_device_uuid = ?',
        whereArgs: [owner.userId, owner.deviceUuid],
        orderBy: 'sequence ASC');
    return rows.map(_actionFromRow).toList();
  }

  Future<void> updateAction(OutboxAction action,
      {required String status,
      int? retryCount,
      DateTime? nextRetryAt,
      String? lastError}) async {
    final db = await database;
    await db.update(
        'outbox_actions',
        {
          'status': status,
          if (retryCount != null) 'retry_count': retryCount,
          'next_retry_at': nextRetryAt?.toUtc().toIso8601String(),
          'last_error': lastError
        },
        where:
            'client_action_id = ? AND owner_user_id = ? AND owner_device_uuid = ?',
        whereArgs: [
          action.clientActionId,
          action.ownerUserId,
          action.ownerDeviceUuid
        ]);
  }

  Future<void> saveCache(String key, Map<String, dynamic> payload,
      {String? revision}) async {
    final db = await database;
    await db.insert(
        'safe_cache',
        {
          'cache_key': key,
          'payload': jsonEncode(payload),
          'revision': revision,
          'saved_at': DateTime.now().toUtc().toIso8601String()
        },
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Map<String, dynamic>?> readCache(String key) async {
    final db = await database;
    final rows = await db.query('safe_cache',
        where: 'cache_key = ?', whereArgs: [key], limit: 1);
    if (rows.isEmpty) return null;
    final payload = jsonDecode(rows.first['payload']! as String);
    return payload is Map<String, dynamic> ? payload : null;
  }

  Future<void> prune({Duration retention = const Duration(days: 7)}) async {
    final db = await database;
    final cutoff = DateTime.now().toUtc().subtract(retention).toIso8601String();
    await db.delete('outbox_actions',
        where: 'status IN (?, ?) AND created_at < ?',
        whereArgs: ['ACKNOWLEDGED', 'FAILED_TERMINAL', cutoff]);
  }

  Future<void> discardOutbox(OfflineOwner owner) async {
    final db = await database;
    await db.delete('outbox_actions',
        where: 'owner_user_id = ? AND owner_device_uuid = ?',
        whereArgs: [owner.userId, owner.deviceUuid]);
  }

  Future<void> clearCache() async {
    final db = await database;
    await db.delete('safe_cache');
  }

  OutboxAction _actionFromRow(Map<String, Object?> row) => OutboxAction.fromMap(
      {...row, 'payload': jsonDecode(row['payload']! as String)});
}
