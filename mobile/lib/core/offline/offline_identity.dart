import 'dart:convert';

class OfflineOwner {
  const OfflineOwner(this.userId, this.deviceUuid);

  final String userId;
  final String deviceUuid;
  String get key => jsonEncode([userId, deviceUuid]);

  bool matches(OfflineOwner? other) =>
      other != null && userId == other.userId && deviceUuid == other.deviceUuid;
}

class OfflineContext {
  const OfflineContext(this.owner, this.sessionId, this.teamId, this.playerId);

  final OfflineOwner owner;
  final String sessionId;
  final String teamId;
  final String playerId;
  String get key =>
      jsonEncode([owner.userId, owner.deviceUuid, sessionId, teamId, playerId]);
}

/// Only identities and participation contexts confirmed by the API enter here.
class OfflineIdentity {
  static final instance = OfflineIdentity();
  OfflineOwner? owner;
  bool authenticationRequired = true;
  int generation = 0;
  final Map<String, OfflineContext> _contexts = {};

  void authenticate(String userId, String deviceUuid) {
    final next = OfflineOwner(userId, deviceUuid);
    if (!next.matches(owner)) _contexts.clear();
    owner = next;
    authenticationRequired = false;
    generation++;
  }

  void pause() {
    authenticationRequired = true;
    generation++;
  }

  void clear() {
    owner = null;
    _contexts.clear();
    pause();
  }

  OfflineContext? context(String sessionId) => _contexts[sessionId];

  OfflineContext bind(String sessionId, Map<String, dynamic> projection) {
    final current = owner;
    final player = projection['player'];
    final team = projection['team_id'];
    if (current == null ||
        authenticationRequired ||
        projection['session_id'] != sessionId ||
        team is! String ||
        player is! Map ||
        player['id'] is! String) {
      throw StateError('A verified gameplay context is required.');
    }
    return _contexts[sessionId] =
        OfflineContext(current, sessionId, team, player['id'] as String);
  }
}
