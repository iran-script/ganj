import 'package:flutter/foundation.dart';

class SessionExpiry extends ChangeNotifier {
  SessionExpiry._();

  static final SessionExpiry instance = SessionExpiry._();

  int _generation = 0;
  int get generation => _generation;

  void expire() {
    _generation++;
    notifyListeners();
  }
}
