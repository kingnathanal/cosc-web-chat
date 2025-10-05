use 436db;
-- users table 
CREATE TABLE if not exists users (
  id              int AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(100) NOT NULL UNIQUE,
  screenName      VARCHAR(100) NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- chatrooms table 
CREATE TABLE if not exists list_of_chatrooms (
  id              int AUTO_INCREMENT PRIMARY KEY,
  chatroomName    VARCHAR(150) NOT NULL UNIQUE,
  -- store null for unlocked rooms; otherwise store a hashed key
  key_hash        VARCHAR(255) NULL,
  creator_user_id int NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (creator_user_id) REFERENCES users(id)
);

-- current chatroom occupants

create table if not exists current_chatroom_occupants(
	id int auto_increment primary key,
    chatroom_id int not null,
    user_id int not null,
    socket_id int not null,
    joined_at timestamp not null default current_timestamp,
    foreign key(chatroom_id) references list_of_chatrooms(id),
    foreign key(user_id) references users(id)
);

-- storing the sockets details
CREATE TABLE if not exists sockets (
	id              int AUTO_INCREMENT PRIMARY KEY,
	user_id         int NOT NULL,
	socket_token    VARCHAR(100) NOT NULL UNIQUE,
	connected_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
	disconnected_at TIMESTAMP NULL DEFAULT NULL,
	FOREIGN KEY (user_id) REFERENCES users(id)

);

-- Persist room messages (for showing messages since join or for auditing)
CREATE TABLE if not exists messages (
  id              int AUTO_INCREMENT PRIMARY KEY,
  chatroom_id     int NOT NULL,
  user_id         int NOT NULL,
  body            TEXT NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (chatroom_id) REFERENCES list_of_chatrooms(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Direct Messages (for the grad-student DM requirement)
CREATE TABLE if not exists direct_messages (
	id              int AUTO_INCREMENT PRIMARY KEY,
	sender_id       int NOT NULL,
	body            TEXT NOT NULL,
	created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id)
);

select * from list_of_chatrooms join users on list_of_chatrooms.creator_user_id = users.id;