import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.Base64;

/**
 * Desktop-container posttx reachability probe (API v3 NOP).
 * Compile: javac -d bin PosttxProbe.java
 * Run:     java -cp bin PosttxProbe http://web/api/posttx.php 901 'RehearseSync1!'
 */
public class PosttxProbe {
  static String encodeTxc(String plain) {
    String b64 = Base64.getEncoder().encodeToString(plain.getBytes(StandardCharsets.UTF_8));
    return b64.replace('/', '-').replace('+', '*').replace('=', '_');
  }

  static String decodeTxc(String enc) {
    String b = enc.trim().replace('-', '/').replace('*', '+').replace('_', '=');
    int pad = (4 - (b.length() % 4)) % 4;
    for (int i = 0; i < pad; i++) {
      b += "=";
    }
    return new String(Base64.getDecoder().decode(b), StandardCharsets.UTF_8);
  }

  public static void main(String[] args) throws Exception {
    String url = args[0];
    String user = args[1];
    String pass = args[2];
    // Matches Transaction.createSingleNopRequestContainer
    String plain = "3;1;" + user + ";" + pass + ";1;0;nop;@All";
    String txc = encodeTxc(plain);
    String body = "txc=" + URLEncoder.encode(txc, "UTF-8");
    HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
    c.setConnectTimeout(15000);
    c.setReadTimeout(30000);
    c.setDoOutput(true);
    c.setRequestMethod("POST");
    c.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");
    try (OutputStream os = c.getOutputStream()) {
      os.write(body.getBytes(StandardCharsets.UTF_8));
    }
    int code = c.getResponseCode();
    InputStream in = (code >= 400) ? c.getErrorStream() : c.getInputStream();
    String raw = "";
    if (in != null) {
      ByteArrayOutputStream buf = new ByteArrayOutputStream();
      byte[] b = new byte[4096];
      int n;
      while ((n = in.read(b)) >= 0) {
        buf.write(b, 0, n);
      }
      raw = buf.toString(StandardCharsets.UTF_8.name()).trim();
    }
    String decoded = raw.isEmpty() ? "" : decodeTxc(raw);
    System.out.println("HTTP " + code);
    System.out.println("decoded: " + decoded.replace("\n", "\\n"));
    if (decoded.toLowerCase().contains("authentication failed")) {
      System.out.println("FAIL: authentication failed");
      System.exit(2);
    }
    if (code != 200) {
      System.out.println("FAIL: HTTP " + code);
      System.exit(3);
    }
    System.out.println("PASS: desktop→posttx reachable");
  }
}
