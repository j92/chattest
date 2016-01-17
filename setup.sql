
/** Create nicknames table
 */
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nickname VARCHAR UNIQUE,
    password VARCHAR
);

INSERT INTO users (
    nickname, password
)
VALUES
( 'joost', '$2y$10$QGD5/Wjl1bcX7uaQmjXL1ulicD0Qli9iuQEeSNnxNwP/oIUKF/RaS');

