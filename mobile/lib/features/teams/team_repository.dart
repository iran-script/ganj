import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';

class TeamMember {
  const TeamMember(
      {required this.userId, required this.name, required this.status});

  final String userId;
  final String name;
  final String status;

  factory TeamMember.fromJson(Map<String, dynamic> json) => TeamMember(
        userId: json['user_id'] as String? ?? '',
        name: json['name'] as String? ?? 'بازیکن',
        status: json['status'] as String? ?? 'ACTIVE',
      );
}

class NexoTeam {
  const NexoTeam({
    required this.id,
    required this.name,
    required this.status,
    required this.captainUserId,
    required this.captainName,
    required this.members,
    required this.eligibleForGame,
  });

  final String id;
  final String name;
  final String status;
  final String captainUserId;
  final String captainName;
  final List<TeamMember> members;
  final bool eligibleForGame;

  factory NexoTeam.fromJson(Map<String, dynamic> json) {
    final captain = json['captain'] as Map<String, dynamic>? ?? const {};
    return NexoTeam(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      status: json['status'] as String? ?? 'UNKNOWN',
      captainUserId: json['captain_user_id'] as String? ?? '',
      captainName: captain['name'] as String? ?? 'کاپیتان',
      members: (json['members'] as List? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(TeamMember.fromJson)
          .toList(growable: false),
      eligibleForGame: json['eligible_for_game'] as bool? ?? false,
    );
  }
}

class TeamRepository {
  TeamRepository(this.api);
  final ApiClient api;
  static const _uuid = Uuid();

  Future<List<NexoTeam>> list() async {
    final response = await api.get('/teams');
    return (response['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(NexoTeam.fromJson)
        .toList(growable: false);
  }

  Future<NexoTeam> create(String name) async => NexoTeam.fromJson(
        await api.post('/teams',
            body: {'name': name.trim()},
            idempotent: true,
            idempotencyKey: _uuid.v4()),
      );

  Future<Map<String, dynamic>> previewInvite(String inviteCode) =>
      api.get('/team-invites/${Uri.encodeComponent(inviteCode.trim())}');

  Future<JoinRequestResult> join(String inviteCode) async =>
      JoinRequestResult.fromJson(await api.post('/teams/join',
            body: {'invite_code': inviteCode.trim()},
            idempotent: true,
            idempotencyKey: _uuid.v4()));

  Future<Map<String, dynamic>> createInvite(String teamId) => api.post(
        '/teams/$teamId/invites',
        body: {'expires_at': DateTime.now().toUtc().add(const Duration(days: 7)).toIso8601String()},
      );

  Future<List<JoinRequest>> joinRequests(String teamId) async {
    final response = await api.get('/teams/$teamId/join-requests');
    return (response['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(JoinRequest.fromJson)
        .toList(growable: false);
  }

  Future<void> respondToJoinRequest(String teamId, String requestId,
      {required bool accept}) async {
    await api.post('/teams/$teamId/join-requests/$requestId/${accept ? 'accept' : 'decline'}',
        idempotent: true,
        idempotencyKey: _uuid.v4());
  }

  Future<Map<String, dynamic>> findPlayer(String publicId) =>
      api.get('/players/${publicId.trim()}');

  Future<void> invitePlayer(String teamId, String publicId) async {
    await api.post('/teams/$teamId/player-invitations',
        body: {'user_id': publicId.trim()},
        idempotent: true,
        idempotencyKey: _uuid.v4());
  }

  Future<List<TeamInvitation>> incomingInvitations() async {
    final response = await api.get('/me/team-invitations');
    return (response['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(TeamInvitation.fromJson)
        .toList(growable: false);
  }

  Future<List<SentTeamInvitation>> outgoingInvitations(String teamId) async {
    final response = await api.get('/teams/$teamId/invites');
    return (response['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(SentTeamInvitation.fromJson)
        .toList(growable: false);
  }

  Future<void> revokeInvitation(String teamId, String invitationId) async {
    await api.delete('/teams/$teamId/invites/$invitationId');
  }

  Future<void> respondToInvitation(String inviteId,
      {required bool accept}) async {
    await api.post(
        '/me/team-invitations/$inviteId/${accept ? 'accept' : 'decline'}',
        idempotent: true,
        idempotencyKey: _uuid.v4());
  }

  Future<void> leave(String teamId) async {
    await api.post('/teams/$teamId/leave');
  }

  Future<void> remove(String teamId, String userId) async {
    await api.delete('/teams/$teamId/members/$userId');
  }

  Future<void> transferCaptaincy(String teamId, String userId) async {
    await api
        .post('/teams/$teamId/transfer-captaincy', body: {'user_id': userId});
  }
}

class JoinRequestResult {
  const JoinRequestResult({required this.id, required this.status, required this.teamName});
  final String id;
  final String status;
  final String teamName;

  factory JoinRequestResult.fromJson(Map<String, dynamic> json) => JoinRequestResult(
        id: json['id'] as String? ?? '',
        status: json['status'] as String? ?? 'UNKNOWN',
        teamName: json['team_name'] as String? ?? '',
      );
}

class JoinRequest {
  const JoinRequest({required this.id, required this.status, required this.playerId, required this.playerName});
  final String id;
  final String status;
  final String playerId;
  final String playerName;

  factory JoinRequest.fromJson(Map<String, dynamic> json) {
    final player = json['player'] as Map<String, dynamic>? ?? const {};
    return JoinRequest(
      id: json['id'] as String? ?? '',
      status: json['status'] as String? ?? 'UNKNOWN',
      playerId: player['id'] as String? ?? '',
      playerName: player['name'] as String? ?? 'بازیکن',
    );
  }
}

class TeamInvitation {
  const TeamInvitation({
    required this.id,
    required this.teamId,
    required this.teamName,
    required this.captainName,
    required this.status,
    this.expiresAt,
  });

  final String id;
  final String teamId;
  final String teamName;
  final String captainName;
  final String status;
  final String? expiresAt;

  bool get isPending {
    final expiry = expiresAt;
    final isExpired = expiry != null &&
        DateTime.tryParse(expiry)?.isBefore(DateTime.now()) == true;
    return status == 'PENDING' && !isExpired;
  }

  String get effectiveStatus =>
      status == 'PENDING' && !isPending ? 'EXPIRED' : status;

  factory TeamInvitation.fromJson(Map<String, dynamic> json) {
    final team = json['team'] as Map<String, dynamic>? ?? const {};
    final captain = json['captain'] as Map<String, dynamic>? ?? const {};
    return TeamInvitation(
      id: json['id'] as String? ?? '',
      teamId: team['id'] as String? ?? '',
      teamName: team['name'] as String? ?? '',
      captainName: captain['name'] as String? ?? '',
      status: json['status'] as String? ?? 'UNKNOWN',
      expiresAt: json['expires_at'] as String?,
    );
  }
}

class SentTeamInvitation {
  const SentTeamInvitation({
    required this.id,
    required this.recipientName,
    required this.decision,
    required this.usesCount,
    this.maxUses,
    this.expiresAt,
    this.revokedAt,
  });

  final String id;
  final String recipientName;
  final String decision;
  final int usesCount;
  final int? maxUses;
  final String? expiresAt;
  final String? revokedAt;

  bool get isPending {
    final expiry = expiresAt;
    final isExpired = expiry != null &&
        DateTime.tryParse(expiry)?.isBefore(DateTime.now()) == true;
    return decision == 'PENDING' && revokedAt == null && !isExpired;
  }

  factory SentTeamInvitation.fromJson(Map<String, dynamic> json) {
    final recipient = json['recipient'] as Map<String, dynamic>? ?? const {};
    return SentTeamInvitation(
      id: json['id'] as String? ?? '',
      recipientName: recipient['name'] as String? ?? 'دعوت با کد تیم',
      decision:
          recipient.isEmpty ? 'CODE' : json['decision'] as String? ?? 'UNKNOWN',
      usesCount: (json['uses_count'] as num?)?.toInt() ?? 0,
      maxUses: (json['max_uses'] as num?)?.toInt(),
      expiresAt: json['expires_at'] as String?,
      revokedAt: json['revoked_at'] as String?,
    );
  }
}

class LobbySession {
  const LobbySession({
    required this.id,
    required this.gameId,
    required this.status,
    required this.teams,
    this.registrationClosesAt,
  });

  final String id;
  final String gameId;
  final String status;
  final List<SessionTeam> teams;
  final String? registrationClosesAt;

  factory LobbySession.fromJson(Map<String, dynamic> json) => LobbySession(
        id: json['id'] as String? ?? '',
        gameId: json['game_id'] as String? ?? '',
        status: json['status'] as String? ?? 'UNKNOWN',
        registrationClosesAt: json['registration_closes_at'] as String?,
        teams: (json['teams'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(SessionTeam.fromJson)
            .toList(growable: false),
      );
}

class SessionTeam {
  const SessionTeam(
      {required this.teamId,
      required this.name,
      required this.status,
      required this.playerCount,
      required this.players});
  final String teamId;
  final String name;
  final String status;
  final int playerCount;
  final List<SessionPlayer> players;

  factory SessionTeam.fromJson(Map<String, dynamic> json) => SessionTeam(
        teamId: json['team_id'] as String? ?? '',
        name: json['name'] as String? ?? 'تیم',
        status: json['status'] as String? ?? 'REGISTERED',
        playerCount: json['player_count'] as int? ?? 0,
        players: (json['players'] as List? ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(SessionPlayer.fromJson)
            .toList(growable: false),
      );
}

class SessionPlayer {
  const SessionPlayer(
      {required this.userId, required this.name, required this.status});
  final String userId;
  final String name;
  final String status;

  factory SessionPlayer.fromJson(Map<String, dynamic> json) {
    final user = json['user'] as Map<String, dynamic>? ?? const {};
    return SessionPlayer(
      userId: user['id'] as String? ?? json['user_id'] as String? ?? '',
      name: user['name'] as String? ?? json['name'] as String? ?? 'بازیکن',
      status: json['status'] as String? ?? 'JOINED',
    );
  }
}

class LobbyRepository {
  LobbyRepository(this.api);
  final ApiClient api;

  Future<List<LobbySession>> sessions() async {
    final response = await api.get('/game-sessions');
    return (response['items'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(LobbySession.fromJson)
        .toList(growable: false);
  }

  Future<LobbySession> session(String id) async => LobbySession.fromJson(
        await api.get('/game-sessions/$id/state'),
      );

  Future<LobbySession> registerTeam(
          String sessionId, String teamId, String key) async =>
      LobbySession.fromJson(await api.post(
        '/game-sessions/$sessionId/register-team',
        body: {'team_id': teamId},
        idempotent: true,
        idempotencyKey: key,
      ));

  Future<LobbySession> ready(String sessionId, bool isReady) async {
    await api.post('/game-sessions/$sessionId/bind-device',
        body: {'device_id': await api.deviceId()});
    return LobbySession.fromJson(await api.post(
      '/game-sessions/$sessionId/ready',
      body: {'ready': isReady},
      device: true,
    ));
  }
}
