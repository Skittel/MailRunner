"""Level 1 of the self test: SmartMailFlow's analysis tests (fixed DNS answers, no network) run against
the vendored analysis library. Run: python3 -I -m unittest discover -s mailpath/tests"""
import importlib.util
import os
import sys
import unittest

VENDOR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "vendor", "smartmailflow")
sys.path.insert(0, VENDOR)                            # package "smartmailflow" (vendored)
sys.path.insert(0, os.path.join(VENDOR, "tests"))     # mailtools

_spec = importlib.util.spec_from_file_location("smf_test_analysis", os.path.join(VENDOR, "tests", "test_analysis.py"))
smf_test_analysis = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(smf_test_analysis)


def load_tests(loader, tests, pattern):
    suite = unittest.TestSuite()
    suite.addTests(loader.loadTestsFromModule(smf_test_analysis))
    return suite
