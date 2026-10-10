import 'dart:convert';

import 'package:path/path.dart' as path;
import 'package:sqflite/sqflite.dart';

import 'outbox_action.dart';

class LocalDatabase {
  LocalDatabase._();

  static final LocalDatabase instance = LocalDatabase._();
  Database? _database;

  Future<Database> get database async {
    if (_database != null) return _database!;
    final root = await getDatabasesPath();
    _database = await openDatabase(path.join(root, 'nexo_player.db'), version: 1, onCreate: (db, version) async {
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
      await db.execute('CREATE INDEX outbox_ready_index ON outbox_actions(status, next_retry_at, sequence)');
      await db.execute('''
        CREATE TABLE safe_cache (
          cache_key TEXT PRIMARY KEY,
          payload TEXT NOT NULL,
          revision TEXT,
          saved_at TEXT NOT NULL
        )
      ''');
    });
    return _database!;
  }

  Future<void> close() async {
    await _database?.close();
    _database = null;
  }

  Future<void> insertAction(OutboxAction action) async {
    final db = await database;
    await db.insert('outbox_actions', {
      ...action.toMap(),
      'payload': jsonEncode(action.payload),
    }, conflictAlgorithm: ConflictAlgorithm.ignore);
  }

  Future<List<OutboxAction>> readyActions({DateTime? now}) async {
    final db = await database;
    final rows = await db.query('outbox_actions', where: 'status IN (?, ?, ?) AND (next_retry_at IS NULL OR next_retry_at <= ?)',
        whereArgs: ['PENDING', 'FAILED_RETRYABLE', 'SENDING', (now ?? DateTime.now()).toUtc().toIso8601String()], orderBy: 'sequence ASC');
    return rows.map(_actionFromRow).toList();
  }

  Future<List<OutboxAction>> allActions() async {
    final db = await database;
    final rows = await db.query('outbox_actions', orderBy: 'sequence ASC');
    return rows.map(_actionFromRow).toList();
  }

  Future<int> nextSequence(String sessionId) async {
    final db = await database;
    final rows = await db.rawQuery('SELECT MAX(sequence) AS max_sequence FROM outbox_actions WHERE session_id = ?', [sessionId]);
    return ((rows.first['max_sequence'] as int?) ?? 0) + 1;
  }

  Future<void> updateAction(String clientActionId, {required String status, int? retryCount, DateTime? nextRetryAt, String? lastError}) async {
    final db = await database;
    await db.update('outbox_actions', {'status': status, if (retryCount != null) 'retry_count': retryCount,
      'next_retry_at': nextRetryAt?.toUtc().toIso8601String(), 'last_error': lastError}, where: 'client_action_id = ?', whereArgs: [clientActionId]);
  }

  Future<void> saveCache(String key, Map<String, dynamic> payload, {String? revision}) async {
    final db = await database;
    await db.insert('safe_cache', {'cache_key': key, 'payload': jsonEncode(payload), 'revision': revision, 'saved_at': DateTime.now().toUtc().toIso8601String()}, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Map<String, dynamic>?> readCache(String key) async {
    final db = await database;
    final rows = await db.query('safe_cache', where: 'cache_key = ?', whereArgs: [key], limit: 1);
    if (rows.isEmpty) return null;
    final payload = jsonDecode(rows.first['payload']! as String);
    return payload is Map<String, dynamic> ? payload : null;
  }

  Future<void> prune({Duration retention = const Duration(days: 7)}) async {
    final db = await database;
    final cutoff = DateTime.now().toUtc().subtract(retention).toIso8601String();
    await db.delete('outbox_actions', where: 'status IN (?, ?) AND created_at < ?', whereArgs: ['ACKNOWLEDGED', 'FAILED_TERMINAL', cutoff]);
  }

  Future<void> discardOutbox() async {
    final db = await database;
    await db.delete('outbox_actions');
  }

  OutboxAction _actionFromRow(Map<String, Object?> row) => OutboxAction.fromMap({...row, 'payload': jsonDecode(row['payload']! as String)});
}
