import '../../core/api/api_client.dart';

class GameRoomSummary {
  const GameRoomSummary(
      {required this.id,
      required this.gameId,
      required this.gameName,
      required this.name,
      required this.status,
      required this.sessionStatus,
      required this.capacity,
      required this.teamCount,
      required this.city,
      required this.teams});

  final String id;
  final String gameId;
  final String gameName;
  final String name;
  final String status;
  final String? sessionStatus;
  final int capacity;
  final int teamCount;
  final String? city;
  final List<Map<String, dynamic>> teams;

  factory GameRoomSummary.fromJson(Map<String, dynamic> json) =>
      GameRoomSummary(
        id: json['id'] as String? ?? '',
        gameId: json['game_id'] as String? ?? '',
        gameName: json['game_name'] as String? ?? '',
        name: json['name'] as String? ?? '',
        status: json['status'] as String? ?? 'OPEN',
        sessionStatus: json['session_status'] as String?,
        capacity: (json['capacity'] as num?)?.toInt() ?? 5,
        teamCount: (json['team_count'] as num?)?.toInt() ?? 0,
        city: json['city'] as String?,
        teams: (json['teams'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .toList(growable: false),
      );
}

class RoomRepository {
  RoomRepository(this.api);
  final ApiClient api;
  Future<List<GameRoomSummary>> list(String gameId) async {
    final response = await api.get('/games/$gameId/rooms');
    return (response['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(GameRoomSummary.fromJson)
        .toList(growable: false);
  }

  Future<GameRoomSummary> show(String roomId) async =>
      GameRoomSummary.fromJson(await api.get('/rooms/$roomId'));

  Future<GameRoomSummary> create(
          String gameId, String name, String teamId, String key) async =>
      GameRoomSummary.fromJson(await api.post(
        '/games/$gameId/rooms',
        body: {'name': name, 'team_id': teamId},
        idempotent: true,
        idempotencyKey: key,
      ));

  Future<GameRoomSummary> addTeam(
          String roomId, String teamId, String key) async =>
      GameRoomSummary.fromJson(await api.post(
        '/rooms/$roomId/teams',
        body: {'team_id': teamId},
        idempotent: true,
        idempotencyKey: key,
      ));
}
