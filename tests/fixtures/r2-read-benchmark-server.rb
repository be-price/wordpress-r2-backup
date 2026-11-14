# Synthetic TLS server bound only to loopback; no R2 or outbound connections.
require 'socket'
require 'openssl'
context = OpenSSL::SSL::SSLContext.new
context.key = OpenSSL::PKey::RSA.new(File.read(ARGV[0]))
context.cert = OpenSSL::X509::Certificate.new(File.read(ARGV[1]))
server = TCPServer.new('127.0.0.1', 0)
listener = OpenSSL::SSL::SSLServer.new(server, context)
STDOUT.sync = true
puts server.addr[1]
loop do
  socket = listener.accept
  Thread.new(socket) do |client|
    begin
      line = client.gets.to_s
      while (header = client.gets) && !header.strip.empty?; end
      method, path = line.split(' ', 3)
      name = path.to_s.split('/').last.to_s
      if name.match?(/\A[a-f0-9]{64}\.age\z/) && %w[HEAD GET].include?(method)
        body = 'synthetic-cipher-' + name
        body += ('x' * 1_048_576) if path.include?('/pages/' + ('e' * 64) + '/')
        body += ('x' * 8_388_608) if path.include?('/packs/' + ('f' * 64) + '/')
        sleep 0.05
        client.write "HTTP/1.1 200 OK\r\nContent-Length: #{body.bytesize}\r\nConnection: close\r\n\r\n"
        client.write(body) if method == 'GET'
      else
        client.write "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
      end
    rescue IOError, SystemCallError, OpenSSL::SSL::SSLError
      # A local client can terminate early in a fault case.
    ensure
      client.close
    end
  end
end
