class OutboxAction {
  const OutboxAction({
    required this.clientActionId,
    required this.actionType,
    required this.sessionId,
    required this.payload,
    required this.sequence,
    required this.createdAt,
    this.teamId,
    this.nodeInstanceId,
    this.retryCount = 0,
    this.nextRetryAt,
    this.status = 'PENDING',
    this.lastError,
  });

  factory OutboxAction.fromMap(Map<String, Object?> map) => OutboxAction(
        clientActionId: map['client_action_id']! as String,
        actionType: map['action_type']! as String,
        sessionId: map['session_id']! as String,
        teamId: map['team_id'] as String?,
        nodeInstanceId: map['node_instance_id'] as String?,
        payload: Map<String, dynamic>.from(map['payload'] as Map),
        sequence: map['sequence']! as int,
        createdAt: DateTime.parse(map['created_at']! as String),
        retryCount: map['retry_count']! as int,
        nextRetryAt: map['next_retry_at'] == null ? null : DateTime.parse(map['next_retry_at']! as String),
        status: map['status']! as String,
        lastError: map['last_error'] as String?,
      );

  final String clientActionId;
  final String actionType;
  final String sessionId;
  final String? teamId;
  final String? nodeInstanceId;
  final Map<String, dynamic> payload;
  final int sequence;
  final DateTime createdAt;
  final int retryCount;
  final DateTime? nextRetryAt;
  final String status;
  final String? lastError;

  Map<String, Object?> toMap() => {
        'client_action_id': clientActionId,
        'action_type': actionType,
        'session_id': sessionId,
        'team_id': teamId,
        'node_instance_id': nodeInstanceId,
        'payload': payload,
        'sequence': sequence,
        'created_at': createdAt.toUtc().toIso8601String(),
        'retry_count': retryCount,
        'next_retry_at': nextRetryAt?.toUtc().toIso8601String(),
        'status': status,
        'last_error': lastError,
      };

  OutboxAction copyWith({
    int? retryCount,
    DateTime? nextRetryAt,
    String? status,
    String? lastError,
  }) => OutboxAction(
        clientActionId: clientActionId,
        actionType: actionType,
        sessionId: sessionId,
        teamId: teamId,
        nodeInstanceId: nodeInstanceId,
        payload: payload,
        sequence: sequence,
        createdAt: createdAt,
        retryCount: retryCount ?? this.retryCount,
        nextRetryAt: nextRetryAt ?? this.nextRetryAt,
        status: status ?? this.status,
        lastError: lastError ?? this.lastError,
      );
}
