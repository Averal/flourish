CREATE TABLE users (
	user_id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	first_name VARCHAR(100) NOT NULL,
	middle_initial VARCHAR(100) NOT NULL DEFAULT '',
	last_name VARCHAR(100) NOT NULL,
	email_address VARCHAR(200) NOT NULL UNIQUE,
	status VARCHAR(8) NOT NULL DEFAULT 'Active' CHECK(status IN ('Active', 'Inactive', 'Pending')),
	times_logged_in INTEGER NOT NULL DEFAULT 0,
	date_created TIMESTAMP NOT NULL DEFAULT CURRENT TIMESTAMP,
	birthday DATE,
	time_of_last_login TIME,
	is_validated CHAR(1) NOT NULL DEFAULT '0' CHECK(is_validated IN ('0', '1')),
	hashed_password VARCHAR(100) NOT NULL
) CCSID UNICODE;
COMMENT ON COLUMN users.hashed_password IS 'This hash is generated using fCryptography::hashPassword()';
COMMENT ON COLUMN users.time_of_last_login IS 'When the user last logged in';
COMMENT ON COLUMN users.birthday IS 'The birthday';


CREATE TABLE groups (
	group_id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	name VARCHAR(255) NOT NULL UNIQUE,
	group_leader INTEGER REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	group_founder INTEGER REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE CASCADE
) CCSID UNICODE;

CREATE TABLE users_groups (
	user_id INTEGER NOT NULL REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	group_id INTEGER NOT NULL REFERENCES groups(group_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	PRIMARY KEY(user_id, group_id)
) CCSID UNICODE;

CREATE TABLE artists (
	artist_id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	name VARCHAR(255) NOT NULL UNIQUE
) CCSID UNICODE;

CREATE TABLE albums (
	album_id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	name VARCHAR(255) NOT NULL,
	year_released INTEGER NOT NULL,
	msrp DECIMAL(10,2) NOT NULL,
	genre VARCHAR(100) NOT NULL DEFAULT '',
	artist_id INTEGER NOT NULL REFERENCES artists(artist_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	UNIQUE (artist_id, name)
) CCSID UNICODE;

CREATE TABLE songs (
	song_id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
	name VARCHAR(255) NOT NULL,
	length TIME NOT NULL,
	album_id INTEGER NOT NULL REFERENCES albums(album_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	track_number INTEGER NOT NULL,
	UNIQUE(track_number, album_id)
) CCSID UNICODE;

CREATE TABLE owns_on_cd (
	user_id INTEGER NOT NULL REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	album_id INTEGER NOT NULL REFERENCES albums(album_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	PRIMARY KEY(user_id, album_id)
) CCSID UNICODE;

CREATE TABLE owns_on_tape (
	user_id INTEGER NOT NULL REFERENCES users(user_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	album_id INTEGER NOT NULL REFERENCES albums(album_id) ON UPDATE NO ACTION ON DELETE CASCADE,
	PRIMARY KEY(user_id, album_id)
) CCSID UNICODE;

CREATE TABLE blobs (
	blob_id INTEGER NOT NULL PRIMARY KEY,
	data BLOB NOT NULL
) CCSID UNICODE;