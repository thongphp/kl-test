-- Separate database for the test suites so RefreshDatabase/DatabaseTruncation never wipe the dev DB.
CREATE DATABASE kl_test_testing OWNER kl_user;
